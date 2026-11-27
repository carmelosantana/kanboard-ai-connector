<?php

require_once 'tests/units/Base.php';
// Data providers build php-agents and Symfony objects before setUpBeforeClass() runs.
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish as UpFinish;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta as UpText;
use CarmeloSantana\PHPAgents\Provider\Usage as UpUsage;
use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Stream\AiCancellation;
use Kanboard\Plugin\AiConnector\Stream\AiRequest;
use Kanboard\Plugin\AiConnector\Stream\AiStream;
use Kanboard\Plugin\AiConnector\Stream\Event\Started;
use Kanboard\Plugin\AiConnector\Test\Fixture\BlockingProvider;
use Kanboard\Plugin\AiConnector\Test\Fixture\ScriptedProvider;
use KanboardTests\units\Base;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * A request with a schema takes the structured path: never streamEvents(), one blocking
 * structured() call (until php-agents ships StructuredStreaming), Started(streaming: false), one
 * StructuredDelta and a Finish carrying the decoded result, charged once.
 */
class AiStreamStructuredBlockingTest extends Base
{
    private const SCHEMA = '{"type":"object","properties":{"title":{"type":"string"}}}';

    /** @var list<string> */
    public array $logged = [];
    public float $now = 0.0;

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

    private function drain(object $provider, string $schema = self::SCHEMA, ?AiCancellation $cancel = null, float $max = 300.0): array
    {
        return iterator_to_array($this->events($provider, $schema, $cancel, $max), false);
    }

    /** Started, then the consumer pauses until $at before asking for the rest. */
    private function pausedAfterStarted(\Generator $events, float $at): array
    {
        $out = [$events->current()];
        $this->now = $at;
        $events->next();
        while ($events->valid()) {
            $out[] = $events->current();
            $events->next();
        }
        return $out;
    }

    /** A ScriptedProvider whose stream would answer as text, so taking the text path shows. */
    private static function scripted(mixed $structured): ScriptedProvider
    {
        $provider = new ScriptedProvider([new UpText('text path'), new UpFinish(ProviderFinishReason::Stop, 'raw', null, 'm')]);
        $provider->structuredAnswer = $structured;
        return $provider;
    }

    /** @return list<string> the class short names, in order */
    private static function shape(array $events): array
    {
        return array_map(fn ($e) => (new \ReflectionClass($e))->getShortName(), $events);
    }

    public function testSchemaRequestNeverCallsStreamEvents(): void
    {
        $provider = self::scripted(['title' => 'Hi']);
        $this->drain($provider, max: 90.0);

        $this->assertSame(['structured'], array_column($provider->calls, 'method'), 'one structured() call, no streamEvents()');
        $call = $provider->calls[0];
        $this->assertCount(1, $call['args'][0]);
        $this->assertInstanceOf(UserMessage::class, $call['args'][0][0]);
        $this->assertSame([], $call['args'][2], 'no options');
        $this->assertEquals(new RequestLimits(idleTimeout: 90.0, maxDuration: 90.0, tickInterval: 0.25), $call['limits'],
            'a blocking answer sends nothing until done: idle = max = the request maximum');
    }

    public function testBlockingLimitsCountFromTheReservation(): void
    {
        $provider = self::scripted(['title' => 'Hi']);
        $this->pausedAfterStarted($this->events($provider, max: 90.0), 30.0);
        $this->assertEquals(90.0 - 30.0, $provider->calls[0]['limits']->maxDuration, 'a pause after Started is spent from the request maximum');
        $this->assertEquals(60.0, $provider->calls[0]['limits']->idleTimeout);
    }

    public function testNoStructuredCallOnceTheDeadlineHasPassed(): void
    {
        foreach ([self::scripted(['title' => 'Hi']), new BlockingProvider(structured: ['title' => 'Hi'])] as $i => $provider) {
            $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
            $this->now = 0.0;
            $events = $this->pausedAfterStarted($this->events($provider, max: 90.0), 90.0);
            $this->assertSame(['Started', 'Error'], self::shape($events), "provider $i");
            $this->assertSame('timeout', $events[1]->code);
            $this->assertSame([], $provider->calls, "provider $i: no call after the deadline");
            $this->assertSame('failed', $this->attemptStatus());
        }
    }

    public function testSchemaRequestYieldsStartedFalseOneStructuredDeltaAndFinishWithResult(): void
    {
        $provider = self::scripted(new Response('{"title": "Hi"}', ProviderFinishReason::Stop, [], 'provider-reported-model', new UpUsage(4, 5, 9)));
        $events = $this->drain($provider);

        $this->assertSame(['Started', 'StructuredDelta', 'Finish'], self::shape($events));
        $this->assertEquals(new Started('model-a', false), $events[0]);
        $this->assertSame('{"title":"Hi"}', $events[1]->json);
        $this->assertSame('stop', $events[2]->reason);
        $this->assertSame('model-a', $events[2]->model);
        $this->assertSame(['title' => 'Hi'], $events[2]->result);
        $this->assertSame(9, $events[2]->usage->totalTokens);
        $this->assertSame('finished', $this->attemptStatus());
    }

    public function testNonAbstractProviderTakesTheSamePath(): void
    {
        $provider = new BlockingProvider(structured: ['title' => 'Hi']);
        $events = $this->drain($provider);
        $this->assertSame(['Started', 'StructuredDelta', 'Finish'], self::shape($events));
        $this->assertSame(['title' => 'Hi'], $events[2]->result);
        $this->assertSame(['structured'], array_column($provider->calls, 'method'));
    }

    public function testSchemaRequestInvalidJsonIsInvalidOutput(): void
    {
        $events = $this->drain(self::scripted(new Response('{"title": oops', ProviderFinishReason::Stop)));
        $this->assertSame(['Started', 'StructuredDelta', 'Finish'], self::shape($events));
        $this->assertSame('invalid_output', $events[2]->reason);
        $this->assertNull($events[2]->result);
        $this->assertSame('finished', $this->attemptStatus(), 'the provider answered: charged');
    }

    public function testSchemaRequestOverTheCapIsOutputTooLarge(): void
    {
        $events = $this->drain(self::scripted(new Response('{"t":"' . str_repeat('x', 262144) . '"}', ProviderFinishReason::Stop)));
        $this->assertSame(['Started', 'Finish'], self::shape($events));
        $this->assertSame('output_too_large', $events[1]->reason);
        $this->assertNull($events[1]->result);
    }

    public function testOverDeepArrayFromTheProviderIsInvalidOutput(): void
    {
        // Providers such as Anthropic (tool_use) hand back an already-decoded array: the depth
        // rule for JSON text applies to it too, so a 200-deep document is not a result.
        $deep = 'leaf';
        for ($i = 0; $i < 200; $i++) {
            $deep = ['n' => $deep];
        }
        foreach ([self::scripted($deep), new BlockingProvider(structured: $deep)] as $i => $provider) {
            $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
            $events = $this->drain($provider);
            $terminal = end($events);
            $this->assertSame('invalid_output', $terminal->reason, "provider $i");
            $this->assertNull($terminal->result, "provider $i");
            $this->assertSame('finished', $this->attemptStatus(), 'the provider answered: charged');
        }
    }

    public function testSchemaRequestIsChargedOnce(): void
    {
        $this->drain(self::scripted(['title' => 'Hi']));
        $this->assertSame(1, (int) $this->container['db']->table(AiQuota::TABLE)->count());
        $this->assertSame(1, (int) $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->count());
        $this->assertSame('finished', $this->attemptStatus());
    }

    public function testSchemaRequestCancelledBeforeTheCallIsFinishCancelled(): void
    {
        $cancel = new class implements AiCancellation {
            public int $asked = 0;
            public function isCancelled(): bool
            {
                return ++$this->asked > 1; // the pre-reserve check passes, the next one cancels
            }
        };
        $provider = self::scripted(['title' => 'Hi']);
        $events = $this->drain($provider, cancel: $cancel);
        $this->assertSame(['Started', 'Finish'], self::shape($events));
        $this->assertFalse($events[0]->streaming);
        $this->assertSame('cancelled', $events[1]->reason);
        $this->assertSame([], $provider->calls, 'no request was made');
        $this->assertSame('finished', $this->attemptStatus(), 'charged: the reservation stands');
    }

    public function testSchemaRequestProviderThrowIsErrorProviderError(): void
    {
        $events = $this->drain(self::scripted(new TransportException('Could not resolve host: secret')));
        $this->assertSame(['Started', 'Error'], self::shape($events));
        $this->assertSame('provider_error', $events[1]->code);
        $this->assertSame('failed', $this->attemptStatus());
        $lines = array_values(array_filter($this->logged, fn ($l) => str_contains($l, 'AI stream failed')));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('TransportException', $lines[0]);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('secret', $line);
        }
    }

    /** Every php-agents finish reason, with the terminal the stream path maps it to. */
    public static function finishReasons(): array
    {
        $cases = [];
        foreach (ProviderFinishReason::cases() as $case) {
            $cases[$case->name] = [$case, match ($case) {
                ProviderFinishReason::Stop => 'stop',
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

    /** @dataProvider finishReasons */
    public function testSchemaRequestHonoursTheProviderFinishReason(ProviderFinishReason $reason, string $expected): void
    {
        $events = $this->drain(self::scripted(new Response('{"title": "Hi"}', $reason, [], 'm', new UpUsage(4, 5, 9))));
        $terminal = end($events);

        if ($reason === ProviderFinishReason::Stop) {
            $this->assertSame(['Started', 'StructuredDelta', 'Finish'], self::shape($events));
            $this->assertSame(['title' => 'Hi'], $terminal->result);
            return;
        }

        if ($expected === 'Error:provider_error') {
            $this->assertSame(['Started', 'Error'], self::shape($events), 'no StructuredDelta for an unfinished document');
            $this->assertSame('provider_error', $terminal->code);
            $this->assertSame('failed', $this->attemptStatus());
            $lines = array_values(array_filter($this->logged, fn ($l) => str_contains($l, 'AI stream failed')));
            $this->assertCount(1, $lines);
            $this->assertStringContainsString('provider finish error', $lines[0]);
            return;
        }

        $this->assertSame(['Started', 'Finish'], self::shape($events), 'no StructuredDelta for an unfinished document');
        $this->assertSame($expected, $terminal->reason);
        $this->assertSame('model-a', $terminal->model);
        $this->assertNull($terminal->result, 'no result unless the provider stopped');
        $this->assertSame(9, $terminal->usage->totalTokens);
        $this->assertSame('finished', $this->attemptStatus(), 'the provider answered: charged');
    }

    public function testBareSchemaAndNamedEnvelopeBothReachTheProvider(): void
    {
        $envelope = '{"name":"title_reply","schema":' . self::SCHEMA . '}';
        foreach ([self::SCHEMA, $envelope] as $schema) {
            $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->remove();
            $provider = self::scripted(['title' => 'Hi']);
            $events = $this->drain($provider, $schema);
            $this->assertSame($schema, $provider->calls[0]['args'][1], 'passed through unchanged');
            $this->assertSame(['title' => 'Hi'], end($events)->result);
        }
    }

    private function attemptStatus(): string
    {
        return (string) $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->findOneColumn('status');
    }
}
