<?php

require_once 'tests/units/Base.php';
// Data providers build php-agents and Symfony objects before setUpBeforeClass() runs.
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish as UpFinish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta as UpReasoning;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta as UpText;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Provider\Usage as UpUsage;
use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Stream\AiCancellation;
use Kanboard\Plugin\AiConnector\Stream\AiRequest;
use Kanboard\Plugin\AiConnector\Stream\AiStream;
use Kanboard\Plugin\AiConnector\Stream\Event\Error;
use Kanboard\Plugin\AiConnector\Stream\Event\Finish;
use Kanboard\Plugin\AiConnector\Stream\Event\Progress;
use Kanboard\Plugin\AiConnector\Stream\Event\ReasoningDelta;
use Kanboard\Plugin\AiConnector\Stream\Event\Started;
use Kanboard\Plugin\AiConnector\Stream\Event\Terminal;
use Kanboard\Plugin\AiConnector\Stream\Event\TextDelta;
use Kanboard\Plugin\AiConnector\Stream\Event\ToolCallEnd;
use Kanboard\Plugin\AiConnector\Stream\Event\ToolCallStart;
use Kanboard\Plugin\AiConnector\Test\Fixture\ScriptedProvider;
use KanboardTests\units\Base;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * AiStream's text stream: php-agents events mapped onto AiConnector's, cancellation on every
 * event, progress at most once a second, exactly one terminal event, and the attempt closed in
 * finally whatever ends the generator.
 */
class AiStreamTextTest extends Base
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

    private function stream(ScriptedProvider $provider, ?AiCancellation $cancel = null, float $idle = 60.0, float $max = 300.0): \Generator
    {
        $registry = new ProviderRegistry($this->container);
        $registry->setProviderForTesting($provider);
        $stream = new AiStream($this->container, null, fn (): float => $this->now);
        $stream->setRegistryForTesting($registry);
        return $stream->events(new AiRequest('t.one', 'k1', [['role' => 'user', 'content' => 'hello']],
            cancel: $cancel, idleTimeout: $idle, maxDuration: $max));
    }

    private function drain(ScriptedProvider $provider, ?AiCancellation $cancel = null): array
    {
        return iterator_to_array($this->stream($provider, $cancel), false);
    }

    private static function finish(ProviderFinishReason $reason = ProviderFinishReason::Stop, ?UpUsage $usage = null, string $model = 'provider-reported-model'): UpFinish
    {
        return new UpFinish($reason, 'raw', $usage, $model);
    }

    private function attemptStatus(): string
    {
        return (string) $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->findOneColumn('status');
    }

    private function cancellation(): object
    {
        return new class implements AiCancellation {
            public bool $cancelled = false;
            public function isCancelled(): bool
            {
                return $this->cancelled;
            }
        };
    }

    /** @return list<string> the class short names, in order */
    private static function shape(array $events): array
    {
        return array_map(fn ($e) => (new \ReflectionClass($e))->getShortName(), $events);
    }

    public function testDeltasReasoningAndToolCallsMapInOrder(): void
    {
        $provider = new ScriptedProvider([
            new UpReasoning('think'),
            new UpText('Hel'),
            new UpText('lo'),
            new ToolInputStart(0, 'call_1', 'lookup.task'),
            new ToolInputDelta(0, 'call_1', '{"id":'),
            new ToolInputDelta(0, 'call_1', '7}'),
            new ToolInputEnd(0, 'call_1', 'lookup.task', ['id' => 7]),
            self::finish(ProviderFinishReason::ToolUse, new UpUsage(10, 5, 15)),
        ]);
        $events = $this->drain($provider);

        $this->assertSame(['Started', 'ReasoningDelta', 'TextDelta', 'TextDelta', 'ToolCallStart', 'ToolCallEnd', 'Finish'], self::shape($events));
        $this->assertTrue($events[0]->streaming);
        $this->assertSame('think', $events[1]->text);
        $this->assertSame('Hel', $events[2]->text);
        $this->assertSame('lo', $events[3]->text);
        $this->assertEquals(new ToolCallStart(0, 'call_1', 'lookup.task'), $events[4]);
        $this->assertEquals(new ToolCallEnd(0, 'call_1', 'lookup.task', ['id' => 7]), $events[5]);
        $this->assertSame('tool_use', $events[6]->reason);
        $this->assertSame([10, 5, 15], [$events[6]->usage->promptTokens, $events[6]->usage->completionTokens, $events[6]->usage->totalTokens]);
        $this->assertNull($events[6]->result);

        $call = $provider->calls[0];
        $this->assertSame('streamEvents', $call['method']);
        $this->assertCount(1, $call['args'][0]);
        $this->assertInstanceOf(UserMessage::class, $call['args'][0][0]);
        $this->assertSame([], $call['args'][1], 'no tools');
        $this->assertSame([], $call['args'][2], 'no options');
        $this->assertEquals(new RequestLimits(idleTimeout: 60.0, maxDuration: 300.0, tickInterval: 0.25), $call['limits']);
        $this->assertSame('abandoned', $provider->ended, 'reading stops at the Finish');
    }

    public function testRequestLimitsComeFromTheClampedRequest(): void
    {
        $provider = new ScriptedProvider([self::finish()]);
        iterator_to_array($this->stream($provider, idle: 0.1, max: 9999.0), false);
        $this->assertEquals(new RequestLimits(idleTimeout: 1.0, maxDuration: 600.0, tickInterval: 0.25), $provider->calls[0]['limits']);
    }

    public static function upstreamFinishReasons(): array
    {
        return [
            'Stop' => [ProviderFinishReason::Stop, Finish::class, 'stop'],
            'ToolUse' => [ProviderFinishReason::ToolUse, Finish::class, 'tool_use'],
            'MaxTokens' => [ProviderFinishReason::MaxTokens, Finish::class, 'max_tokens'],
            'Refusal' => [ProviderFinishReason::Refusal, Finish::class, 'refusal'],
            'ContentFilter' => [ProviderFinishReason::ContentFilter, Finish::class, 'content_filter'],
            'ContextWindowExceeded' => [ProviderFinishReason::ContextWindowExceeded, Finish::class, 'context_window_exceeded'],
            'PauseTurn' => [ProviderFinishReason::PauseTurn, Finish::class, 'incomplete'],
            'Error' => [ProviderFinishReason::Error, Error::class, 'provider_error'],
        ];
    }

    /** @dataProvider upstreamFinishReasons */
    #[\PHPUnit\Framework\Attributes\DataProvider('upstreamFinishReasons')]
    public function testEveryUpstreamFinishReasonMaps(ProviderFinishReason $reason, string $class, string $code): void
    {
        $this->assertCount(8, ProviderFinishReason::cases(), 'a new upstream reason needs a mapping');
        $events = $this->drain(new ScriptedProvider([new UpText('x'), self::finish($reason)]));
        $last = end($events);
        $this->assertInstanceOf($class, $last);
        $this->assertSame($code, $last instanceof Finish ? $last->reason : $last->code);
        $this->assertSame($class === Finish::class ? 'finished' : 'failed', $this->attemptStatus());
    }

    public static function iteratorExceptions(): array
    {
        return [
            'timeout' => [new TimeoutException('Idle timeout reached for "http://h/secret".'), 'timeout'],
            'timeout wrapped' => [new \RuntimeException('outer', 0, new TimeoutException('inner secret')), 'timeout'],
            'transport' => [new TransportException('Could not resolve host: secret'), 'provider_error'],
            'other' => [new \RuntimeException('HTTP 500 secret body'), 'provider_error'],
        ];
    }

    /** @dataProvider iteratorExceptions */
    #[\PHPUnit\Framework\Attributes\DataProvider('iteratorExceptions')]
    public function testIteratorExceptionsMap(\Throwable $thrown, string $code): void
    {
        $events = $this->drain(new ScriptedProvider([new UpText('partial')], $thrown));
        $this->assertSame(['Started', 'TextDelta', 'Error'], self::shape($events));
        $error = $events[2];
        $this->assertSame($code, $error->code);
        $this->assertSame($code === 'timeout' ? 'The AI request timed out. Try again.' : 'The AI provider failed to answer. Try again later.', $error->message);
        $this->assertStringNotContainsString('secret', $error->message);
        $this->assertSame('failed', $this->attemptStatus());

        $lines = array_values(array_filter($this->logged, fn ($l) => str_contains($l, 'AI stream failed')));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString("($code)", $lines[0]);
        $this->assertStringContainsString((new \ReflectionClass($thrown))->getShortName(), $lines[0], 'the class is logged');
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('secret', $line, 'never the exception message');
        }
    }

    public function testStreamEndingWithoutFinishIsProviderError(): void
    {
        $events = $this->drain(new ScriptedProvider([new UpText('cut off')]));
        $this->assertSame(['Started', 'TextDelta', 'Error'], self::shape($events));
        $this->assertSame('provider_error', $events[2]->code);
        $this->assertSame('failed', $this->attemptStatus());
    }

    public function testCancelAfterReserveBeforeRequestIsFinishCancelled(): void
    {
        $cancel = new class implements AiCancellation {
            public int $asked = 0;
            public function isCancelled(): bool
            {
                return ++$this->asked > 1; // the pre-reserve check passes, the next one cancels
            }
        };
        $provider = new ScriptedProvider([new UpText('never'), self::finish()]);
        $events = $this->drain($provider, $cancel);
        $this->assertSame(['Started', 'Finish'], self::shape($events));
        $this->assertSame('cancelled', $events[1]->reason);
        $this->assertSame([], $provider->calls, 'no request was made');
        $this->assertSame('finished', $this->attemptStatus(), 'charged: the reservation stands');
    }

    public function testCancelMidStreamIsFinishCancelledAndStopsReading(): void
    {
        $cancel = $this->cancellation();
        $provider = new ScriptedProvider([new UpText('one'), new UpText('two'), self::finish()]);
        $events = [];
        foreach ($this->stream($provider, $cancel) as $event) {
            $events[] = $event;
            if ($event instanceof TextDelta) {
                $cancel->cancelled = true;
            }
        }
        $this->assertSame(['Started', 'TextDelta', 'Finish'], self::shape($events));
        $this->assertSame('cancelled', $events[2]->reason);
        $this->assertSame('model-a', $events[2]->model);
        $this->assertNull($events[2]->usage);
        $this->assertNull($events[2]->result);
        $this->assertSame('abandoned', $provider->ended, 'AiStream stopped reading');
        $this->assertSame('finished', $this->attemptStatus());
    }

    public function testCancelOnATickIsSeen(): void
    {
        $cancel = $this->cancellation();
        $provider = new ScriptedProvider([
            new Tick(),
            fn () => $cancel->cancelled = true,
            new Tick(),
            new UpText('never'),
            self::finish(),
        ]);
        $events = $this->drain($provider, $cancel);
        $this->assertSame(['Started', 'Finish'], self::shape($events));
        $this->assertSame('cancelled', $events[1]->reason);
        $this->assertSame('abandoned', $provider->ended);
    }

    public function testCancelRaisedJustBeforeTheFinishIsReadLetsTheFinishStand(): void
    {
        $cancel = $this->cancellation();
        // The token is raised between the last delta and the Finish, with no event in between to
        // see it: the Finish is taken without a token check, so the provider's answer stands.
        $provider = new ScriptedProvider([new UpText('done'), fn () => $cancel->cancelled = true, self::finish()]);
        $events = $this->drain($provider, $cancel);
        $this->assertSame(['Started', 'TextDelta', 'Finish'], self::shape($events));
        $this->assertSame('stop', $events[2]->reason);
    }

    public function testReadingStopsRightAfterTheFinish(): void
    {
        $cancel = $this->cancellation();
        $readOn = false;
        $provider = new ScriptedProvider([
            new UpText('done'),
            self::finish(),
            function () use (&$readOn, $cancel) {
                $readOn = true;
                $cancel->cancelled = true;
            },
            new UpText('after the finish'),
        ]);
        $events = $this->drain($provider, $cancel);
        $this->assertSame(['Started', 'TextDelta', 'Finish'], self::shape($events));
        $this->assertSame('stop', $events[2]->reason, 'a received Finish wins');
        $this->assertFalse($readOn, 'nothing past the Finish is read');
        $this->assertSame('abandoned', $provider->ended);
    }

    public static function transportThrowsAroundMaxDuration(): array
    {
        return [
            'at max duration' => [5.0, 'timeout'],
            'past max duration' => [7.5, 'timeout'],
            'before max duration' => [4.9, 'provider_error'],
        ];
    }

    /** @dataProvider transportThrowsAroundMaxDuration */
    #[\PHPUnit\Framework\Attributes\DataProvider('transportThrowsAroundMaxDuration')]
    public function testTransportThrowAtMaxDurationIsTimeout(float $elapsed, string $code): void
    {
        $provider = new ScriptedProvider([fn () => $this->now = $elapsed], new TransportException('Max duration was reached for "http://h/secret".'));
        $events = iterator_to_array($this->stream($provider, max: 5.0), false);
        $this->assertSame(['Started', 'Error'], self::shape($events));
        $this->assertSame($code, $events[1]->code);
    }

    public function testNonTransportThrowAtMaxDurationStaysProviderError(): void
    {
        $provider = new ScriptedProvider([fn () => $this->now = 9.0], new \RuntimeException('max duration secret'));
        $events = iterator_to_array($this->stream($provider, max: 5.0), false);
        $this->assertSame('provider_error', end($events)->code);
    }

    public function testProgressAtMostOncePerSecond(): void
    {
        $script = [];
        for ($i = 1; $i <= 25; $i++) {
            $script[] = fn () => $this->now = $i / 10;
            $script[] = new Tick();
        }
        $script[] = self::finish();
        $events = $this->drain(new ScriptedProvider($script));

        $progress = array_values(array_filter($events, fn ($e) => $e instanceof Progress));
        $this->assertCount(2, $progress, '2.5 s of ticks: one Progress a second');
        $this->assertSame([1000, 2000], array_map(fn ($p) => $p->elapsedMs, $progress));
        $this->assertSame(0, $progress[0]->receivedChars);
    }

    public function testTenTicksInOneSecondGiveOneProgress(): void
    {
        $script = [];
        for ($i = 1; $i <= 10; $i++) {
            $script[] = fn () => $this->now = $i / 10;
            $script[] = $i === 5 ? new UpText('héllo') : new Tick();
        }
        $script[] = new UpReasoning('ab');
        $script[] = self::finish();
        $events = $this->drain(new ScriptedProvider($script));

        $progress = array_values(array_filter($events, fn ($e) => $e instanceof Progress));
        $this->assertCount(1, $progress);
        $this->assertSame(1000, $progress[0]->elapsedMs);
        $this->assertSame(5, $progress[0]->receivedChars, 'characters, not bytes, of text so far');
    }

    public function testIdleUntilTimeoutIsAnErrorTimeout(): void
    {
        $script = [];
        for ($i = 1; $i <= 8; $i++) {
            $script[] = fn () => $this->now = $i * 0.25;
            $script[] = new Tick();
        }
        $events = $this->drain(new ScriptedProvider($script, new TimeoutException('Idle timeout reached')));
        $this->assertSame(['Started', 'Progress', 'Progress', 'Error'], self::shape($events));
        $this->assertSame('timeout', $events[3]->code);
        $this->assertSame('failed', $this->attemptStatus());
    }

    public function testBadToolNameOrIdIsDroppedAndLogged(): void
    {
        $badName = 'evil name<script>';
        $badId = "id\nwith-newline-secret";
        $provider = new ScriptedProvider([
            new ToolInputStart(0, 'call_ok', 'good_tool'),
            new ToolInputStart(1, 'call_2', $badName),
            new ToolInputStart(2, $badId, 'other_tool'),
            new ToolInputStart(3, str_repeat('x', 129), 'long_id'),
            new ToolInputStart(4, 'call_5', str_repeat('n', 65)),
            new ToolInputEnd(0, 'call_ok', 'good_tool', []),
            new ToolInputEnd(1, 'call_2', $badName, []),
            new ToolInputEnd(2, $badId, 'other_tool', []),
            new ToolInputEnd(3, str_repeat('x', 129), 'long_id', []),
            new ToolInputEnd(4, 'call_5', str_repeat('n', 65), []),
            self::finish(ProviderFinishReason::ToolUse),
        ]);
        $events = $this->drain($provider);
        $this->assertSame(['Started', 'ToolCallStart', 'ToolCallEnd', 'Finish'], self::shape($events));
        $this->assertSame('call_ok', $events[1]->id);
        $this->assertSame('call_ok', $events[2]->id);

        $lines = array_values(array_filter($this->logged, fn ($l) => str_contains($l, 'dropped a tool call')));
        $this->assertCount(4, $lines, 'one line per dropped call');
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('script', $line);
            $this->assertStringNotContainsString('secret', $line);
            $this->assertStringNotContainsString('xxxx', $line);
        }
    }

    public function testFinishModelIsTheProfileModelNotTheProviders(): void
    {
        $events = $this->drain(new ScriptedProvider([self::finish(model: 'provider-reported-model')]));
        $this->assertSame('model-a', $events[0]->model);
        $this->assertSame('model-a', end($events)->model);
    }

    public function testExactlyOneTerminalAndNothingAfterIt(): void
    {
        $scripts = [
            [self::finish()],
            [new UpText('a'), self::finish(ProviderFinishReason::Error)],
            [new UpText('a')],
            [new Tick(), new UpText('a'), self::finish(), new UpText('after the finish'), self::finish()],
        ];
        foreach ($scripts as $i => $script) {
            $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
            $events = $this->drain(new ScriptedProvider($script));
            $terminals = array_keys(array_filter($events, fn ($e) => $e instanceof Terminal));
            $this->assertCount(1, $terminals, "script $i: one terminal");
            $this->assertSame(count($events) - 1, $terminals[0], "script $i: last");
        }
    }

    public function testAbandonedGeneratorClosesTheAttempt(): void
    {
        $provider = new ScriptedProvider([new UpText('first'), new UpText('second'), self::finish()]);
        $generator = $this->stream($provider);
        foreach ($generator as $event) {
            if ($event instanceof TextDelta) {
                break;
            }
        }
        $this->assertSame('running', $this->attemptStatus(), 'still open while the generator lives');
        unset($generator);
        $this->assertSame('failed', $this->attemptStatus());
        $this->assertSame('abandoned', $provider->ended);
    }

    public function testDrainedStreamClosesTheAttemptFinished(): void
    {
        $this->drain(new ScriptedProvider([new UpText('a'), self::finish()]));
        $this->assertSame('finished', $this->attemptStatus());
        $this->assertSame(1, (int) $this->container['db']->table(AiQuota::TABLE)->count(), 'charged once');
    }

    public function testNoKeyOrBaseUrlInAnyEvent(): void
    {
        $events = $this->drain(new ScriptedProvider([
            new UpReasoning('r'), new UpText('t'), new Tick(),
            new ToolInputStart(0, 'c', 'n'), new ToolInputEnd(0, 'c', 'n', []),
            self::finish(ProviderFinishReason::Stop, new UpUsage(1, 2, 3)),
        ]));
        $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
        $events[] = $this->drain(new ScriptedProvider([], new TransportException('x')))[1];
        $dump = serialize($events) . var_export($events, true);
        $this->assertStringNotContainsString('sk-stored-secret-key', $dump);
        $this->assertStringNotContainsString('ollama-secret-host', $dump);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('sk-stored-secret-key', $line);
            $this->assertStringNotContainsString('ollama-secret-host', $line);
        }
    }
}
