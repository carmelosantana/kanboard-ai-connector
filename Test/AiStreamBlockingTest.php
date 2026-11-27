<?php

require_once 'tests/units/Base.php';
// Data providers build php-agents and Symfony objects before setUpBeforeClass() runs.
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta as UpReasoning;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta as UpText;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Provider\Usage as UpUsage;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Stream\AiCancellation;
use Kanboard\Plugin\AiConnector\Stream\AiRequest;
use Kanboard\Plugin\AiConnector\Stream\AiStream;
use Kanboard\Plugin\AiConnector\Stream\Event\Error;
use Kanboard\Plugin\AiConnector\Stream\Event\Finish;
use Kanboard\Plugin\AiConnector\Stream\Event\Started;
use Kanboard\Plugin\AiConnector\Stream\Event\ToolCallEnd;
use Kanboard\Plugin\AiConnector\Stream\Event\ToolCallStart;
use Kanboard\Plugin\AiConnector\Stream\Internal\BlockingCall;
use Kanboard\Plugin\AiConnector\Stream\Internal\StructuredDecoder;
use Kanboard\Plugin\AiConnector\Test\Fixture\BlockingProvider;
use Kanboard\Plugin\AiConnector\Test\Fixture\ScriptedProvider;
use KanboardTests\units\Base;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * The blocking path (one chat() call for providers that are not AbstractProvider, shaped like the
 * stream) and the one-time blocking fallback when an endpoint rejects the streamed request.
 */
class AiStreamBlockingTest extends Base
{
    /** @var list<string> */
    public array $logged = [];
    public float $now = 0.0;
    private int $ana;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        require_once __DIR__ . '/Fixture/ScriptedProvider.php';
        require_once __DIR__ . '/Fixture/BlockingProvider.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'XAI_API_KEY', 'GEMINI_API_KEY', 'MISTRAL_API_KEY'] as $v) {
            putenv($v . '=');
        }
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__ . "/../Schema/$driver.php";
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\AiConnector\Schema\version_1($pdo);
        \Kanboard\Plugin\AiConnector\Schema\version_2($pdo);
        \Kanboard\Plugin\AiConnector\Schema\version_3($pdo);

        $test = $this;
        $this->container['logger'] = new class($test) extends \Psr\Log\AbstractLogger {
            public function __construct(private $test) {}
            public function log($level, $message, array $context = []): void
            {
                $this->test->logged[] = (string) $message;
            }
        };

        $this->ana = (int) $this->container['userModel']->create(['username' => 'ana']);
        $_SESSION['user'] = ['id' => $this->ana, 'username' => 'ana'];

        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'A', 'label' => 'A', 'provider' => 'ollama', 'model' => 'model-a', 'base_url' => 'http://ollama-secret-host.example:11434', 'available' => true],
        ]));
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'A');
        $this->setting(ProviderRegistry::KEY_PREFIX . 'A', 'sk-stored-secret-key');
        $this->container['hook']->on(ProviderRegistry::FEATURES_HOOK, fn () => [['id' => 't.one', 'label' => 'One', 'plugin' => 'T']]);
    }

    private function setting(string $option, string $value): void
    {
        $db = $this->container['db'];
        $db->table('settings')->eq('option', $option)->remove();
        $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        $this->container['memoryCache']->flush();
    }

    private function drain(object $provider, ?AiCancellation $cancel = null): array
    {
        $registry = new ProviderRegistry($this->container);
        $registry->setProviderForTesting($provider);
        $stream = new AiStream($this->container, null, fn (): float => $this->now);
        $stream->setRegistryForTesting($registry);
        return iterator_to_array($stream->events(new AiRequest('t.one', 'k1', [['role' => 'user', 'content' => 'hello']], cancel: $cancel)), false);
    }

    /** @return list<string> the class short names, in order */
    private static function shape(array $events): array
    {
        return array_map(fn ($e) => (new \ReflectionClass($e))->getShortName(), $events);
    }

    private function attemptStatus(): string
    {
        return (string) $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->findOneColumn('status');
    }

    private function countRows(): int
    {
        return (int) $this->container['db']->table(AiQuota::TABLE)->count();
    }

    /** @return list<string> */
    private function logLines(string $needle): array
    {
        return array_values(array_filter($this->logged, fn ($l) => str_contains($l, $needle)));
    }

    /** A real Symfony HTTP exception for $status, obtained through MockHttpClient; its body is never read. */
    private static function httpError(int $status): HttpExceptionInterface
    {
        $response = (new MockHttpClient(new MockResponse('{"error":"secret body"}', [
            'http_code' => $status,
            // JSON, so Symfony puts the body's error text into the exception message: a regression
            // that logged getMessage() or read the body would show 'secret body' in the log.
            'response_headers' => ['content-type: application/json'],
        ])))
            ->request('POST', 'http://scripted.invalid/v1/chat/completions');
        try {
            $response->getHeaders();
        } catch (HttpExceptionInterface $e) {
            return $e;
        }
        throw new \LogicException("HTTP $status did not throw");
    }

    private static function answer(string $content = 'the answer', ?UpUsage $usage = null): Response
    {
        return new Response($content, ProviderFinishReason::Stop, [], 'provider-reported-model', $usage);
    }

    private static function report(?UpUsage $usage): UsageReport
    {
        return new UsageReport('chat', 'test', 'model-a', 'provider-reported-model', $usage, null, null, ProviderFinishReason::Stop, 5, false);
    }

    // --- The blocking path ---------------------------------------------------------------

    public function testNonStreamingProviderGetsOneBlockingCall(): void
    {
        $provider = new BlockingProvider(new Response('Hello there', ProviderFinishReason::Stop, [], 'provider-reported-model', new UpUsage(3, 4, 7), 'thinking'));
        $events = $this->drain($provider);

        $this->assertSame(['Started', 'ReasoningDelta', 'TextDelta', 'Finish'], self::shape($events));
        $this->assertEquals(new Started('model-a', false), $events[0]);
        $this->assertSame('thinking', $events[1]->text);
        $this->assertSame('Hello there', $events[2]->text);
        $this->assertSame('stop', $events[3]->reason);
        $this->assertSame('model-a', $events[3]->model, 'the configured model, not the provider\'s');
        $this->assertSame([3, 4, 7], [$events[3]->usage->promptTokens, $events[3]->usage->completionTokens, $events[3]->usage->totalTokens]);
        $this->assertNull($events[3]->result);

        $this->assertCount(1, $provider->calls);
        $call = $provider->calls[0];
        $this->assertSame('chat', $call['method']);
        $this->assertCount(1, $call['args'][0]);
        $this->assertInstanceOf(UserMessage::class, $call['args'][0][0]);
        $this->assertSame([], $call['args'][1], 'no tools');
        $this->assertSame([], $call['args'][2], 'no options');
        $this->assertSame('finished', $this->attemptStatus());
        $this->assertSame(1, $this->countRows(), 'charged once');
    }

    public function testNoBlockingCallOnceTheDeadlineHasPassed(): void
    {
        $provider = new BlockingProvider(new Response('late', ProviderFinishReason::Stop));
        $registry = new ProviderRegistry($this->container);
        $registry->setProviderForTesting($provider);
        $stream = new AiStream($this->container, null, fn (): float => $this->now);
        $stream->setRegistryForTesting($registry);
        $events = $stream->events(new AiRequest('t.one', 'k1', [['role' => 'user', 'content' => 'hello']], maxDuration: 30.0));

        $this->assertFalse($events->current()->streaming);
        $this->now = 30.0; // the consumer holds Started until the deadline
        $events->next();
        $this->assertInstanceOf(Error::class, $events->current());
        $this->assertSame('timeout', $events->current()->code);
        $events->next();
        $this->assertFalse($events->valid());
        $this->assertSame([], $provider->calls, 'no provider request after the deadline');
        $this->assertSame('failed', $this->attemptStatus());
    }

    public function testEmptyBlockingAnswerHasNoDelta(): void
    {
        $events = $this->drain(new BlockingProvider(self::answer('')));
        $this->assertSame(['Started', 'Finish'], self::shape($events));
    }

    public function testBlockingToolCallsBecomeStartEndPairs(): void
    {
        $provider = new BlockingProvider(new Response('', ProviderFinishReason::ToolUse, [
            new ToolCall('call_1', 'lookup.task', ['id' => 7]),
            new ToolCall("bad\nid-secret", 'other_tool', []),
            new ToolCall('call_3', 'evil name<script>', []),
            new ToolCall('call_4', 'second', ['q' => 'x']),
        ]));
        $events = $this->drain($provider);

        $this->assertSame(['Started', 'ToolCallStart', 'ToolCallEnd', 'ToolCallStart', 'ToolCallEnd', 'Finish'], self::shape($events));
        $this->assertEquals(new ToolCallStart(0, 'call_1', 'lookup.task'), $events[1]);
        $this->assertEquals(new ToolCallEnd(0, 'call_1', 'lookup.task', ['id' => 7]), $events[2]);
        $this->assertEquals(new ToolCallStart(3, 'call_4', 'second'), $events[3]);
        $this->assertEquals(new ToolCallEnd(3, 'call_4', 'second', ['q' => 'x']), $events[4]);
        $this->assertSame('tool_use', $events[5]->reason);

        $this->assertCount(2, $this->logLines('dropped a tool call'), 'one line per dropped call, as on the stream');
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('secret', $line);
            $this->assertStringNotContainsString('script', $line);
        }
    }

    public function testBlockingUsageComesFromTheListener(): void
    {
        $provider = new BlockingProvider(self::answer('a', new UpUsage(1, 2, 3)));
        $provider->report = self::report(new UpUsage(11, 22, 33));
        $events = $this->drain($provider);

        $finish = end($events);
        $this->assertSame([11, 22, 33], [$finish->usage->promptTokens, $finish->usage->completionTokens, $finish->usage->totalTokens]);
        $this->assertTrue($provider->calls[0]['listener'], 'a listener was attached for the call');
    }

    public function testBlockingUsageFallsBackToTheResponse(): void
    {
        $events = $this->drain(new BlockingProvider(self::answer('a', new UpUsage(1, 2, 3))));
        $finish = end($events);
        $this->assertSame([1, 2, 3], [$finish->usage->promptTokens, $finish->usage->completionTokens, $finish->usage->totalTokens]);

        $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
        $events = $this->drain(new BlockingProvider(self::answer('a')));
        $this->assertNull(end($events)->usage);
    }

    public static function finishReasons(): array
    {
        return [
            'Stop' => [ProviderFinishReason::Stop, 'stop'],
            'ToolUse' => [ProviderFinishReason::ToolUse, 'tool_use'],
            'MaxTokens' => [ProviderFinishReason::MaxTokens, 'max_tokens'],
            'Refusal' => [ProviderFinishReason::Refusal, 'refusal'],
            'ContentFilter' => [ProviderFinishReason::ContentFilter, 'content_filter'],
            'ContextWindowExceeded' => [ProviderFinishReason::ContextWindowExceeded, 'context_window_exceeded'],
            'PauseTurn' => [ProviderFinishReason::PauseTurn, 'incomplete'],
            'Error' => [ProviderFinishReason::Error, 'provider_error'],
        ];
    }

    /** @dataProvider finishReasons */
    #[\PHPUnit\Framework\Attributes\DataProvider('finishReasons')]
    public function testBlockingFinishReasonsMapAsTheStreamDoes(ProviderFinishReason $reason, string $code): void
    {
        $this->assertCount(8, ProviderFinishReason::cases(), 'a new upstream reason needs a mapping');
        $events = $this->drain(new BlockingProvider(new Response('x', $reason)));
        $last = end($events);
        if ($reason === ProviderFinishReason::Error) {
            $this->assertInstanceOf(Error::class, $last);
            $this->assertSame('provider_error', $last->code);
            $this->assertSame('failed', $this->attemptStatus());
            $this->assertCount(1, $this->logLines('AI stream failed (provider_error)'));
        } else {
            $this->assertInstanceOf(Finish::class, $last);
            $this->assertSame($code, $last->reason);
            $this->assertSame('finished', $this->attemptStatus());
        }
    }

    public static function blockingExceptions(): array
    {
        return [
            'timeout' => [new TimeoutException('Idle timeout reached for "http://h/secret".'), 'timeout'],
            'transport' => [new TransportException('Could not resolve host: secret'), 'provider_error'],
            'other' => [new \RuntimeException('HTTP 500 secret body'), 'provider_error'],
        ];
    }

    /** @dataProvider blockingExceptions */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockingExceptions')]
    public function testBlockingExceptionsMapLikeTheStream(\Throwable $thrown, string $code): void
    {
        $events = $this->drain(new BlockingProvider($thrown));
        $this->assertSame(['Started', 'Error'], self::shape($events));
        $this->assertSame($code, $events[1]->code);
        $this->assertSame('failed', $this->attemptStatus());
        $lines = $this->logLines('AI stream failed');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString("($code)", $lines[0]);
        $this->assertStringContainsString((new \ReflectionClass($thrown))->getShortName(), $lines[0]);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('secret', $line);
        }
    }

    public function testBlockingCancelIsCheckedBeforeTheCall(): void
    {
        $cancel = new class implements AiCancellation {
            public int $asked = 0;
            public function isCancelled(): bool
            {
                return ++$this->asked > 1; // the pre-reserve check passes, the next one cancels
            }
        };
        $provider = new BlockingProvider(self::answer());
        $events = $this->drain($provider, $cancel);
        $this->assertSame(['Started', 'Finish'], self::shape($events));
        $this->assertFalse($events[0]->streaming);
        $this->assertSame('cancelled', $events[1]->reason);
        $this->assertSame([], $provider->calls);
        $this->assertSame('finished', $this->attemptStatus(), 'charged: the reservation stands');
    }

    // --- BlockingCall::structured() (Task 10's fallback) ---------------------------------

    /** @return list<object> */
    private static function structured(mixed $answer, ?UsageReport $report = null): array
    {
        $provider = new BlockingProvider(structured: $answer);
        $provider->report = $report;
        $events = iterator_to_array((new BlockingCall('model-a'))->structured($provider, [new UserMessage('x')], '{"type":"object"}'), false);
        Assert::assertSame('structured', $provider->calls[0]['method']);
        Assert::assertSame('{"type":"object"}', $provider->calls[0]['args'][1]);
        return $events;
    }

    public function testBlockingStructuredArrayIsOneDeltaAndAResult(): void
    {
        $events = self::structured(['a' => 1], self::report(new UpUsage(5, 6, 11)));
        $this->assertSame(['Started', 'StructuredDelta', 'Finish'], self::shape($events));
        $this->assertFalse($events[0]->streaming);
        $this->assertSame('{"a":1}', $events[1]->json);
        $this->assertSame('stop', $events[2]->reason);
        $this->assertSame(['a' => 1], $events[2]->result);
        $this->assertSame(11, $events[2]->usage->totalTokens);
    }

    public function testBlockingStructuredResponseDecodes(): void
    {
        $events = self::structured(new Response('{"b": [1, 2]}', ProviderFinishReason::Stop, [], '', new UpUsage(1, 1, 2)));
        $this->assertSame(['Started', 'StructuredDelta', 'Finish'], self::shape($events));
        $this->assertSame('{"b":[1,2]}', $events[1]->json);
        $this->assertSame(['b' => [1, 2]], $events[2]->result);
        $this->assertSame(2, $events[2]->usage->totalTokens);
    }

    public function testBlockingStructuredThatDoesNotDecodeIsInvalidOutput(): void
    {
        foreach ([new Response('not json', ProviderFinishReason::Stop), new Response('"scalar"', ProviderFinishReason::Stop), []] as $answer) {
            $events = self::structured($answer);
            $last = end($events);
            $this->assertInstanceOf(Finish::class, $last);
            $this->assertSame('invalid_output', $last->reason);
            $this->assertNull($last->result);
        }
        $events = self::structured(new Response('not json', ProviderFinishReason::Stop));
        $this->assertSame('not json', $events[1]->json, 'the raw content');
    }

    public function testBlockingStructuredOverTheCapIsOutputTooLarge(): void
    {
        $this->assertSame(262144, StructuredDecoder::MAX_BYTES);
        // 'é' is two bytes: 131,073 characters are over 256 KiB, though under 256 Ki characters.
        $json = '"' . str_repeat('é', 131072) . '"';
        $events = self::structured(new Response($json, ProviderFinishReason::Stop));
        $this->assertSame(['Started', 'Finish'], self::shape($events));
        $this->assertSame('output_too_large', $events[1]->reason);
        $this->assertNull($events[1]->result);
    }

    // --- The fallback when a streamed request is rejected --------------------------------

    public static function rejectedStatuses(): array
    {
        return ['400' => [400], '404' => [404], '405' => [405], '415' => [415], '422' => [422]];
    }

    /** @dataProvider rejectedStatuses */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedStatuses')]
    public function testRejectedStreamFallsBackOnceUnderTheSameCharge(int $status): void
    {
        $provider = new ScriptedProvider([new Tick(), new Tick(), fn () => $this->now = 40.0], self::httpError($status));
        $provider->chatAnswer = self::answer('blocking answer', new UpUsage(2, 3, 5));
        $events = $this->drain($provider);

        // The consumer already holds Started(streaming: true); the fallback adds no second Started.
        $this->assertSame(['Started', 'TextDelta', 'Finish'], self::shape($events));
        $this->assertTrue($events[0]->streaming);
        $this->assertSame('blocking answer', $events[1]->text);
        $this->assertSame('stop', $events[2]->reason);
        $this->assertSame('model-a', $events[2]->model);
        $this->assertSame(5, $events[2]->usage->totalTokens);

        $this->assertSame(['streamEvents', 'chat'], array_column($provider->calls, 'method'), 'two provider calls');
        $this->assertSame([], $provider->calls[1]['args'][1]);
        $this->assertEquals(new RequestLimits(idleTimeout: 260.0, maxDuration: 260.0, tickInterval: 0.25), $provider->calls[1]['limits'],
            'a blocking answer sends nothing until done: idle = max = what is left of the 300 s');
        $this->assertSame(1, $this->countRows(), 'one count row: charged once');
        $this->assertSame(1, (int) $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->count(), 'one attempt');
        $this->assertSame('finished', $this->attemptStatus());

        $this->assertSame(["streaming rejected (HTTP $status), blocking fallback"], array_map(
            fn ($l) => substr($l, strpos($l, 'streaming rejected'), strlen("streaming rejected (HTTP $status), blocking fallback")),
            $this->logLines('streaming rejected')));
        $this->assertSame([], $this->logLines('AI stream failed'));
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('secret body', $line, 'the body is never read or logged');
        }
    }

    public function testFallbackGetsExactlyTheTimeLeft(): void
    {
        $provider = new ScriptedProvider([fn () => $this->now = 299.5], self::httpError(400));
        $provider->chatAnswer = self::answer();
        $this->drain($provider);
        $this->assertEquals(new RequestLimits(idleTimeout: 0.5, maxDuration: 0.5, tickInterval: 0.25), $provider->calls[1]['limits'],
            'no floor: the fallback never runs past the request maximum');
    }

    public function testNoFallbackOnceTheDeadlineHasPassed(): void
    {
        foreach ([300.0, 301.0] as $at) {
            $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
            $this->logged = [];
            $this->now = 0.0;
            $provider = new ScriptedProvider([fn () => $this->now = $at], self::httpError(400));
            $provider->chatAnswer = self::answer();
            $events = $this->drain($provider);
            $this->assertSame(['Started', 'Error'], self::shape($events), "rejected at $at");
            $this->assertSame('timeout', $events[1]->code);
            $this->assertSame(['streamEvents'], array_column($provider->calls, 'method'), 'no blocking call');
            $this->assertSame('failed', $this->attemptStatus());
            $this->assertCount(1, $this->logLines('AI stream failed (timeout)'));
        }
    }

    public function testWrappedRejectionFallsBack(): void
    {
        $provider = new ScriptedProvider([], ProviderException::chatFailed('rejected', self::httpError(400)));
        $provider->chatAnswer = self::answer();
        $events = $this->drain($provider);
        $this->assertSame(['Started', 'TextDelta', 'Finish'], self::shape($events));
        $this->assertSame(['streamEvents', 'chat'], array_column($provider->calls, 'method'));
    }

    public function testFallbackThatFailsEndsWithOneError(): void
    {
        $provider = new ScriptedProvider([], self::httpError(400));
        $provider->chatAnswer = new TransportException('Could not resolve host: secret');
        $events = $this->drain($provider);
        $this->assertSame(['Started', 'Error'], self::shape($events));
        $this->assertSame('provider_error', $events[1]->code);
        $this->assertSame(['streamEvents', 'chat'], array_column($provider->calls, 'method'), 'no third call');
        $this->assertSame('failed', $this->attemptStatus());
        $this->assertSame(1, $this->countRows());
    }

    public static function notFallbackStatuses(): array
    {
        return ['401' => [401], '403' => [403], '429' => [429], '500' => [500], '502' => [502], '503' => [503]];
    }

    /** @dataProvider notFallbackStatuses */
    #[\PHPUnit\Framework\Attributes\DataProvider('notFallbackStatuses')]
    public function testNoFallbackOn401403429Or5xx(int $status): void
    {
        $provider = new ScriptedProvider([new Tick()], self::httpError($status));
        $provider->chatAnswer = self::answer();
        $events = $this->drain($provider);
        $this->assertSame(['Started', 'Error'], self::shape($events));
        $this->assertSame('provider_error', $events[1]->code);
        $this->assertSame(['streamEvents'], array_column($provider->calls, 'method'));
        $this->assertSame([], $this->logLines('streaming rejected'));
        $this->assertSame('failed', $this->attemptStatus());
    }

    public static function outputBeforeTheRejection(): array
    {
        return [
            'text' => [[new UpText('partial')]],
            'reasoning' => [[new Tick(), new UpReasoning('r')]],
            'tool start' => [[new ToolInputStart(0, 'c', 'n')]],
            'tool delta (no AiConnector event)' => [[new ToolInputDelta(0, 'c', '{')]],
        ];
    }

    /** @dataProvider outputBeforeTheRejection */
    #[\PHPUnit\Framework\Attributes\DataProvider('outputBeforeTheRejection')]
    public function testNoFallbackAfterAnyOutput(array $script): void
    {
        $provider = new ScriptedProvider($script, self::httpError(400));
        $provider->chatAnswer = self::answer();
        $events = $this->drain($provider);
        $last = end($events);
        $this->assertInstanceOf(Error::class, $last);
        $this->assertSame('provider_error', $last->code);
        $this->assertSame(['streamEvents'], array_column($provider->calls, 'method'));
        $this->assertSame([], $this->logLines('streaming rejected'));
    }

    public function testNoFallbackAfterCancel(): void
    {
        $cancel = new class implements AiCancellation {
            public bool $cancelled = false;
            public function isCancelled(): bool
            {
                return $this->cancelled;
            }
        };

        // Cancelled, then the rejection arrives with no event between: still no blocking call.
        $provider = new ScriptedProvider([fn () => $cancel->cancelled = true], self::httpError(400));
        $provider->chatAnswer = self::answer();
        $events = $this->drain($provider, $cancel);
        $this->assertSame(['Started', 'Finish'], self::shape($events));
        $this->assertSame('cancelled', $events[1]->reason, 'cancelled before a Finish arrived');
        $this->assertSame(['streamEvents'], array_column($provider->calls, 'method'));
        $this->assertSame([], $this->logLines('streaming rejected'));
        $this->assertSame([], $this->logLines('AI stream failed'));
        $this->assertSame('finished', $this->attemptStatus());

        // Cancelled, then any other throw: Finish(cancelled) too.
        $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
        $cancel->cancelled = false;
        $provider = new ScriptedProvider([fn () => $cancel->cancelled = true], new TransportException('aborted'));
        $events = $this->drain($provider, $cancel);
        $this->assertSame(['Started', 'Finish'], self::shape($events));
        $this->assertSame('cancelled', $events[1]->reason);
        $this->assertSame([], $this->logLines('AI stream failed'));

        // Cancelled and seen on a tick: the stream stops with Finish(cancelled), nothing follows.
        $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
        $cancel->cancelled = false;
        $provider = new ScriptedProvider([new Tick(), fn () => $cancel->cancelled = true, new Tick()], self::httpError(400));
        $provider->chatAnswer = self::answer();
        $events = $this->drain($provider, $cancel);
        $this->assertSame(['Started', 'Finish'], self::shape($events));
        $this->assertSame('cancelled', $events[1]->reason);
        $this->assertSame(['streamEvents'], array_column($provider->calls, 'method'));
    }
}
