<?php

require_once 'tests/units/Base.php';
// Data providers build php-agents objects before setUpBeforeClass() runs.
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\HttpStatusException;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish as UpFinish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta as UpReasoning;
use CarmeloSantana\PHPAgents\Provider\Stream\StructuredDelta as UpStructured;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Usage as UpUsage;
use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Stream\AiCancellation;
use Kanboard\Plugin\AiConnector\Stream\AiRequest;
use Kanboard\Plugin\AiConnector\Stream\AiStream;
use Kanboard\Plugin\AiConnector\Stream\Event\Started;
use Kanboard\Plugin\AiConnector\Stream\Event\StructuredDelta;
use Kanboard\Plugin\AiConnector\Test\Fixture\ScriptedProvider;
use Kanboard\Plugin\AiConnector\Test\Fixture\StructuredScriptedProvider;
use KanboardTests\units\Base;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A schema request to a provider that implements php-agents' StructuredStreaming is streamed:
 * Started(streaming: true), the document's fragments as StructuredDelta, Progress heartbeats, and
 * a Finish whose result is the decoded document, checked against the same caps as the blocking path.
 */
class AiStreamStructuredTest extends Base
{
    private const SCHEMA = '{"type":"object","properties":{"title":{"type":"string"}}}';

    /** @var list<string> */
    public array $logged = [];
    public float $now = 0.0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once __DIR__ . '/Fixture/ScriptedProvider.php';
        require_once __DIR__ . '/Fixture/StructuredScriptedProvider.php';
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

        $ana = (int) $this->container['userModel']->create(['username' => 'ana']);
        $_SESSION['user'] = ['id' => $ana, 'username' => 'ana'];
        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'A', 'label' => 'A', 'provider' => 'ollama', 'model' => 'model-a', 'base_url' => 'http://ollama-secret-host.example:11434', 'available' => true],
        ]));
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'A');
        $this->container['hook']->on(ProviderRegistry::FEATURES_HOOK, fn () => [['id' => 't.one', 'label' => 'One', 'plugin' => 'T']]);
    }

    private function setting(string $option, string $value): void
    {
        $db = $this->container['db'];
        $db->table('settings')->eq('option', $option)->remove();
        $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        $this->container['memoryCache']->flush();
    }

    private function events(object $provider, string $schema = self::SCHEMA, ?AiCancellation $cancel = null, float $max = 300.0): \Generator
    {
        $registry = new ProviderRegistry($this->container);
        $registry->setProviderForTesting($provider);
        $stream = new AiStream($this->container, null, fn (): float => $this->now);
        $stream->setRegistryForTesting($registry);
        return $stream->events(new AiRequest('t.one', 'k1', [['role' => 'user', 'content' => 'title this']],
            schema: $schema, cancel: $cancel, maxDuration: $max));
    }

    private function drain(object $provider, string $schema = self::SCHEMA, ?AiCancellation $cancel = null): array
    {
        return iterator_to_array($this->events($provider, $schema, $cancel), false);
    }

    /** @param list<object> $script */
    private static function provider(array $script, ?\Throwable $throw = null): StructuredScriptedProvider
    {
        return new StructuredScriptedProvider($script, $throw);
    }

    private static function finish(ProviderFinishReason $reason = ProviderFinishReason::Stop): UpFinish
    {
        return new UpFinish($reason, 'raw', new UpUsage(4, 5, 9), 'provider-reported-model');
    }

    /** @return list<object> the document as fragments, then a Finish(stop) */
    private static function document(string ...$fragments): array
    {
        return [...array_map(fn ($f) => new UpStructured($f), $fragments), self::finish()];
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

    public function testStructuredDeltasThenFinishWithResult(): void
    {
        $provider = self::provider(self::document('{"title":', ' "Hi"}'));
        $events = $this->drain($provider);

        $this->assertSame(['Started', 'StructuredDelta', 'StructuredDelta', 'Finish'], self::shape($events));
        $this->assertEquals(new Started('model-a', true), $events[0]);
        $this->assertSame('{"title":', $events[1]->json);
        $this->assertSame(' "Hi"}', $events[2]->json);
        $this->assertSame('stop', $events[3]->reason);
        $this->assertSame(['title' => 'Hi'], $events[3]->result);
        $this->assertSame(9, $events[3]->usage->totalTokens);
        $this->assertSame('model-a', $events[3]->model);
        $this->assertSame('finished', $this->attemptStatus());
        $this->assertSame(['structuredEvents'], array_column($provider->calls, 'method'), 'never structured() or streamEvents()');
        $this->assertSame(self::SCHEMA, $provider->calls[0]['args'][1]);
        $this->assertSame(1, (int) $this->container['db']->table(AiQuota::TABLE)->count(), 'charged once');
    }

    public function testReasoningAndTicksAreMapped(): void
    {
        $events = $this->drain(self::provider([new UpReasoning('think'), new Tick(), ...self::document('{"title":"Hi"}')]));
        $this->assertSame(['Started', 'ReasoningDelta', 'StructuredDelta', 'Finish'], self::shape($events));
    }

    public function testStreamedCallGetsTheRequestLimits(): void
    {
        $provider = self::provider(self::document('{"title":"Hi"}'));
        $this->now = 100.0;
        iterator_to_array($this->events($provider, max: 120.0), false);
        $limits = $provider->calls[0]['limits'];
        $this->assertEqualsWithDelta(60.0, $limits->idleTimeout, 0.001);
        $this->assertEqualsWithDelta(120.0, $limits->maxDuration, 0.001);
    }

    public function testInvalidJsonIsInvalidOutput(): void
    {
        $events = $this->drain(self::provider(self::document('{"title": oops')));
        $terminal = end($events);
        $this->assertSame('invalid_output', $terminal->reason);
        $this->assertNull($terminal->result);
        $this->assertSame(9, $terminal->usage->totalTokens);
        $this->assertSame('finished', $this->attemptStatus(), 'the provider answered: charged');
    }

    public function testStopWithoutDocumentIsInvalidOutput(): void
    {
        foreach ([[], ['{}'], ['[]']] as $i => $fragments) {
            $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
            $events = $this->drain(self::provider(self::document(...$fragments)));
            $this->assertSame('invalid_output', end($events)->reason, "case $i");
            $this->assertNull(end($events)->result);
        }
    }

    public function testOverTheCapIsOutputTooLargeAndStopsReading(): void
    {
        $chunk = str_repeat('x', 100_000);
        $provider = self::provider(self::document('{"t":"', $chunk, $chunk, $chunk, '"}'));
        $events = $this->drain($provider);

        $this->assertSame(['Started', 'StructuredDelta', 'StructuredDelta', 'StructuredDelta', 'Finish'], self::shape($events),
            'the fragment that crosses 256 KiB is never emitted');
        $this->assertSame('output_too_large', end($events)->reason);
        $this->assertNull(end($events)->result);
        $this->assertSame('abandoned', $provider->ended, 'reading stops at the cap');
        $emitted = array_sum(array_map(fn ($e) => $e instanceof StructuredDelta ? strlen($e->json) : 0, $events));
        $this->assertLessThanOrEqual(262144, $emitted);
    }

    public function testCapCountsBytesNotCharacters(): void
    {
        // 100,000 four-byte characters: 400,000 bytes, well under 262,144 characters.
        $events = $this->drain(self::provider(self::document('{"t":"', str_repeat('😀', 100_000), '"}')));
        $this->assertSame('output_too_large', end($events)->reason);
    }

    public function testDepthOf64IsInvalidOutput(): void
    {
        $deep = str_repeat('[', 64) . str_repeat(']', 64);
        $events = $this->drain(self::provider(self::document($deep)));
        $this->assertSame('invalid_output', end($events)->reason);
        $this->assertNull(end($events)->result);
    }

    /** Every php-agents finish reason but Stop, with the terminal the stream maps it to. */
    public static function nonStopReasons(): array
    {
        $cases = [];
        foreach (ProviderFinishReason::cases() as $case) {
            if ($case === ProviderFinishReason::Stop) {
                continue;
            }
            $cases[$case->name] = [$case, match ($case) {
                ProviderFinishReason::ToolUse => 'tool_use',
                ProviderFinishReason::MaxTokens => 'max_tokens',
                ProviderFinishReason::Refusal => 'refusal',
                ProviderFinishReason::ContentFilter => 'content_filter',
                ProviderFinishReason::ContextWindowExceeded => 'context_window_exceeded',
                ProviderFinishReason::PauseTurn => 'incomplete',
                ProviderFinishReason::Error => 'Error:provider_error',
            }];
        }
        return $cases;
    }

    /** @dataProvider nonStopReasons */
    public function testNonStopFinishCarriesNoResult(ProviderFinishReason $reason, string $expected): void
    {
        // A streamed refusal arrives as fragments then Finish(Refusal): the fragments were shown,
        // but the terminal carries no result.
        $events = $this->drain(self::provider([new UpStructured('{"title":"Hi"}'), self::finish($reason)]));
        $terminal = end($events);
        if ($expected === 'Error:provider_error') {
            $this->assertSame('provider_error', $terminal->code);
            $this->assertSame('failed', $this->attemptStatus());
            return;
        }
        $this->assertSame($expected, $terminal->reason);
        $this->assertNull($terminal->result);
        $this->assertSame(9, $terminal->usage->totalTokens);
    }

    public function testProviderWithoutStructuredStreamingUsesBlockingStructured(): void
    {
        $provider = new ScriptedProvider();
        $provider->structuredAnswer = ['title' => 'Hi'];
        $events = $this->drain($provider);
        $this->assertFalse($events[0]->streaming);
        $this->assertSame(['structured'], array_column($provider->calls, 'method'));
        $this->assertSame(['title' => 'Hi'], end($events)->result);
    }

    public function testBareSchemaAndNamedEnvelopeBothWork(): void
    {
        $envelope = '{"name":"title_reply","schema":' . self::SCHEMA . '}';
        foreach ([self::SCHEMA, $envelope] as $schema) {
            $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
            $provider = self::provider(self::document('{"title":"Hi"}'));
            $events = $this->drain($provider, $schema);
            $this->assertSame($schema, $provider->calls[0]['args'][1]);
            $this->assertSame(['title' => 'Hi'], end($events)->result);
        }
    }

    public function testCancelMidStreamIsFinishCancelled(): void
    {
        $cancel = $this->cancellation();
        $provider = self::provider([new UpStructured('{"title":'), fn () => $cancel->cancelled = true, new Tick(), ...self::document('"Hi"}')]);
        $events = $this->drain($provider, cancel: $cancel);
        $this->assertSame('cancelled', end($events)->reason);
        $this->assertNull(end($events)->result);
        $this->assertSame('abandoned', $provider->ended);
    }

    public function testAHostPausedPastTheDeadlineGetsNoResult(): void
    {
        $provider = self::provider(self::document('{"title":', '"Hi"}'));
        $events = [];
        foreach ($this->events($provider) as $event) {
            $events[] = $event;
            if ($event instanceof StructuredDelta) {
                $this->now = 300.0;
            }
        }
        $this->assertSame(['Started', 'StructuredDelta', 'Error'], self::shape($events));
        $this->assertSame('timeout', end($events)->code);
    }

    public function testProgressWhileTheDocumentStreams(): void
    {
        $provider = self::provider([new UpStructured('{"title":'), fn () => $this->now = 1.5, new Tick(), ...self::document('"Hi"}')]);
        $events = $this->drain($provider);
        $this->assertContains('Progress', self::shape($events));
        $progress = array_values(array_filter($events, fn ($e) => $e instanceof \Kanboard\Plugin\AiConnector\Stream\Event\Progress))[0];
        $this->assertSame(9, $progress->receivedChars, 'the fragment characters received so far');
    }

    /** A status-only php-agents 0.21 error for $status whose response records cancel(). */
    private function statusError(int $status, ?object &$response = null): HttpStatusException
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        return HttpStatusException::fromResponse($response);
    }

    public function testRejectedStreamFallsBackToOneBlockingStructuredCall(): void
    {
        $error = $this->statusError(400, $response);
        $response->expects($this->once())->method('cancel');
        $provider = self::provider([], $error);
        $provider->structuredAnswer = ['title' => 'Hi'];
        $events = $this->drain($provider);

        $this->assertSame(['Started', 'StructuredDelta', 'Finish'], self::shape($events), 'no second Started');
        $this->assertTrue($events[0]->streaming);
        $this->assertSame(['title' => 'Hi'], end($events)->result);
        $this->assertSame(['structuredEvents', 'structured'], array_column($provider->calls, 'method'));
        $this->assertSame(1, (int) $this->container['db']->table(AiQuota::TABLE)->count(), 'one charge');
    }

    public function testAServerErrorIsProviderErrorAndItsResponseIsCancelled(): void
    {
        $error = $this->statusError(503, $response);
        $response->expects($this->once())->method('cancel');
        $response->expects($this->never())->method('getContent');
        $events = $this->drain(self::provider([], $error));
        $this->assertSame('provider_error', end($events)->code);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('ollama-secret-host', $line);
        }
    }

    public function testAnErrorAfterOutputIsNotAFallback(): void
    {
        $provider = self::provider([new UpStructured('{"title":')], $this->statusError(400));
        $events = $this->drain($provider);
        $this->assertSame('provider_error', end($events)->code);
        $this->assertSame(['structuredEvents'], array_column($provider->calls, 'method'));
    }

    /** The host stops at the terminal and keeps the generator: the provider stream must already be closed. */
    private function endedAtTheTerminal(StructuredScriptedProvider $provider, ?AiCancellation $cancel = null, ?\Closure $onDelta = null): ?string
    {
        $generator = $this->events($provider, cancel: $cancel);
        foreach ($generator as $event) {
            if ($event instanceof StructuredDelta && $onDelta !== null) {
                $onDelta();
            }
            if ($event instanceof \Kanboard\Plugin\AiConnector\Stream\Event\Terminal) {
                return $provider->ended;
            }
        }
        return 'no terminal';
    }

    public function testTheUpstreamIsClosedBeforeTheTerminalReachesTheHost(): void
    {
        $chunk = str_repeat('x', 200_000);
        $this->assertSame('abandoned', $this->endedAtTheTerminal(self::provider(self::document('{"t":"', $chunk, $chunk, '"}'))), 'byte cap');

        $cancel = $this->cancellation();
        $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
        $this->assertSame('abandoned', $this->endedAtTheTerminal(self::provider(self::document('{"t":', '1}')), $cancel,
            fn () => $cancel->cancelled = true), 'cancel');

        $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
        $this->assertSame('abandoned', $this->endedAtTheTerminal(self::provider(self::document('{"t":', '1}')), null,
            fn () => $this->now = 300.0), 'deadline');
    }

    public function testASynchronousThrowFromStructuredEventsEndsWithOneError(): void
    {
        $provider = self::provider([]);
        $provider->throwOnCall = new \TypeError('schema must be an array');
        $events = $this->drain($provider);
        $this->assertSame(['Started', 'Error'], self::shape($events));
        $this->assertSame('provider_error', end($events)->code);
        $this->assertSame('failed', $this->attemptStatus());
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('schema must be', $line);
        }
    }

    public function testEmptyFragmentsAreNotEmitted(): void
    {
        $events = $this->drain(self::provider(self::document('', '{"title":"Hi"}', '', '')));
        $this->assertSame(['Started', 'StructuredDelta', 'Finish'], self::shape($events));
        $this->assertSame(['title' => 'Hi'], end($events)->result);
    }
}
