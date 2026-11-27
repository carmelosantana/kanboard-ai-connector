<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\AiConnector\Model\ModelRouting;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Model\UrlGuard;
use Kanboard\Plugin\AiConnector\Stream\AiCancellation;
use Kanboard\Plugin\AiConnector\Stream\AiRequest;
use Kanboard\Plugin\AiConnector\Stream\AiStream;
use Kanboard\Plugin\AiConnector\Stream\Event\Refused;
use Kanboard\Plugin\AiConnector\Stream\Event\Started;
use Kanboard\Plugin\AiConnector\Test\Fixture\ScriptedProvider;
use KanboardTests\units\Base;

/**
 * AiStream's guard rails: gate, principal, feature, routing, build, cancellation and the AiQuota
 * reservation, in that order, each refusal a single Refused event with nothing sent and nothing
 * charged that the spec says is uncharged.
 */
class AiStreamGuardTest extends Base
{
    /** @var list<string> */
    public array $logged = [];
    private int $ana;
    private int $bob;
    private int $project;
    private ScriptedProvider $provider;

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
        $this->bob = (int) $this->container['userModel']->create(['username' => 'bob']);
        $this->signIn($this->ana);

        // A is the keyless default; B and C are offered to projects; C has no model of its own.
        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'A', 'label' => 'A', 'provider' => 'ollama', 'model' => 'model-a', 'base_url' => '', 'available' => false],
            ['id' => 'B', 'label' => 'B', 'provider' => 'ollama', 'model' => 'model-b', 'base_url' => '', 'available' => true],
            ['id' => 'C', 'label' => 'C', 'provider' => 'ollama', 'model' => '', 'base_url' => '', 'available' => true],
        ]));
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'A');
        $this->container['hook']->on(ProviderRegistry::FEATURES_HOOK, fn () => [['id' => 't.one', 'label' => 'One', 'plugin' => 'T']]);
        $this->project = (int) $this->container['projectModel']->create(['name' => 'Routed']);

        $this->provider = new ScriptedProvider();
    }

    private function signIn(int $userId): void
    {
        $_SESSION['user'] = ['id' => $userId, 'username' => 'u' . $userId];
    }

    private function setting(string $option, string $value): void
    {
        $db = $this->container['db'];
        $db->table('settings')->eq('option', $option)->remove();
        $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        $this->container['memoryCache']->flush();
    }

    private function request(string $feature = 't.one', string $key = 'k1', ?AiCancellation $cancel = null, ?int $project = null): AiRequest
    {
        return new AiRequest($feature, $key, [['role' => 'user', 'content' => 'hello']], projectId: $project, cancel: $cancel);
    }

    /** Drains events() with the scripted provider injected (unless $inject is false). */
    private function drain(AiRequest $request, bool $inject = true, ?AiQuota $quota = null): array
    {
        $registry = new ProviderRegistry($this->container);
        if ($inject) {
            $registry->setProviderForTesting($this->provider);
        }
        $stream = new AiStream($this->container, $quota);
        $stream->setRegistryForTesting($registry);
        return iterator_to_array($stream->events($request), false);
    }

    private function counted(): int
    {
        return (int) $this->container['db']->table(AiQuota::TABLE)->count();
    }

    private function attempts(): int
    {
        return (int) $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->count();
    }

    private function assertRefused(array $events, string $code, ?string $message = null): Refused
    {
        $this->assertCount(1, $events, 'exactly one event');
        $this->assertInstanceOf(Refused::class, $events[0]);
        $this->assertSame($code, $events[0]->code);
        $this->assertNotSame('', $events[0]->message);
        if ($message !== null) {
            $this->assertSame($message, $events[0]->message);
        }
        $this->assertSame([], $this->provider->calls, 'no provider call');
        return $events[0];
    }

    private function assertUncharged(): void
    {
        $this->assertSame(0, $this->counted(), 'nothing counted');
        $this->assertSame(0, $this->attempts(), 'no attempt opened');
    }

    public function testNotReadyIsRefused(): void
    {
        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'D', 'label' => 'D', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '', 'available' => true],
        ]));
        $this->assertRefused($this->drain($this->request()), 'not_ready', 'AI is not set up.');
        $this->assertUncharged();
        $this->assertCount(1, array_filter($this->logged, fn ($l) => str_contains($l, 'not_ready')));
    }

    public function testNoSignedInUserIsRefused(): void
    {
        unset($_SESSION['user']);
        $this->assertRefused($this->drain($this->request()), 'not_signed_in', 'Sign in to use AI features.');
        $this->assertUncharged();
    }

    public function testPrincipalIsCheckedBeforeFeature(): void
    {
        unset($_SESSION['user']);
        $this->assertRefused($this->drain($this->request('t.unregistered')), 'not_signed_in');
        $this->assertUncharged();
    }

    public function testReservationRefusalIsLoggedOnce(): void
    {
        $this->setting(AiQuota::MAX_INPUT_CHARS, '3');
        $this->assertRefused($this->drain($this->request()), 'too_long');
        $this->assertCount(1, $this->logged, "AiQuota's line only");
    }

    public function testRefusalLogKeepsOnlyTheShortExceptionClass(): void
    {
        $registry = new class($this->container) extends ProviderRegistry {
            public function buildProvider(?string $profileId = null): \CarmeloSantana\PHPAgents\Contract\ProviderInterface
            {
                throw new class('secret') extends \RuntimeException {};
            }
        };
        $stream = new AiStream($this->container);
        $stream->setRegistryForTesting($registry);
        $events = iterator_to_array($stream->events($this->request()), false);
        $this->assertRefused($events, 'setup_failed');
        $line = array_values(array_filter($this->logged, fn ($l) => str_contains($l, 'setup_failed')))[0];
        $this->assertStringEndsWith(', RuntimeException', $line, 'an anonymous class is cut at the @');
        $this->assertStringNotContainsString('\\', $line, 'no namespace');
        $this->assertStringNotContainsString('.php', $line);
    }

    public function testUnknownFeatureIsRefusedBeforeRouting(): void
    {
        // With a dangling default, routing would refuse no_profile; the feature check runs first.
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'gone');
        $this->assertRefused($this->drain($this->request('t.unregistered')), 'unknown_feature',
            'AI is unavailable right now. Try again later.');
        $this->assertUncharged();
    }

    public function testUnknownFeatureRefusalNeverEchoesTheFeature(): void
    {
        $feature = 'Evil<script>"\'%s';
        $refused = $this->assertRefused($this->drain($this->request($feature)), 'unknown_feature');
        $this->assertStringNotContainsString('Evil', $refused->message);
        $this->assertStringNotContainsString('script', $refused->message);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('<script>', $line);
            $this->assertStringNotContainsString('"', $line);
        }
        $this->assertNotEmpty(array_filter($this->logged, fn ($l) => str_contains($l, 'unknown_feature')));
    }

    public function testNoProfileIsRefusedAndUncharged(): void
    {
        // A dangling default: the gate passes (B and C are ready) but nothing routes to them.
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'gone');
        $this->assertRefused($this->drain($this->request()), 'no_profile', 'AI is unavailable right now. Try again later.');
        $this->assertUncharged();
    }

    public function testBuildFailureIsSetupFailedAndUncharged(): void
    {
        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'L', 'label' => 'L', 'provider' => 'openai', 'model' => 'gpt', 'base_url' => 'http://127.0.0.1:9/v1', 'available' => true],
        ]));
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'L');
        $this->setting(ProviderRegistry::KEY_PREFIX . 'L', 'sk-secret-key');
        UrlGuard::setResolverForTesting(fn (string $host): array => []);
        try {
            $events = $this->drain($this->request(), inject: false);
        } finally {
            UrlGuard::setResolverForTesting(null);
        }
        $refused = $this->assertRefused($events, 'setup_failed', 'AI is unavailable right now. Try again later.');
        $this->assertUncharged();
        $lines = array_values(array_filter($this->logged, fn ($l) => str_contains($l, 'setup_failed')));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('UnsafeUrlException', $lines[0], 'the exception class is logged');
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('127.0.0.1', $line, 'never the URL');
            $this->assertStringNotContainsString('sk-secret-key', $line);
        }
        $this->assertStringNotContainsString('127.0.0.1', $refused->message);
    }

    public static function reservationRefusals(): array
    {
        return [
            'too_long' => ['too_long'],
            'duplicate_attempt' => ['duplicate_attempt'],
            'too_many_running' => ['too_many_running'],
            'quota' => ['quota'],
            'unavailable' => ['unavailable'],
            'not_signed_in (unknown user)' => ['not_signed_in'],
        ];
    }

    /** @dataProvider reservationRefusals */
    #[\PHPUnit\Framework\Attributes\DataProvider('reservationRefusals')]
    public function testQuotaTooLongDuplicateTooManyAndUnavailableMapToRefused(string $code): void
    {
        $quota = new AiQuota($this->container);
        $expected = [
            'too_long' => null,
            'duplicate_attempt' => 'This AI request was already sent.',
            'too_many_running' => 'You have too many AI requests running. Wait for one to finish and try again.',
            'quota' => null,
            'unavailable' => 'AI is unavailable right now. Try again later.',
            'not_signed_in' => 'Sign in to use AI features.',
        ][$code];
        $real = $this->container['db'];
        switch ($code) {
            case 'too_long':
                $this->setting(AiQuota::MAX_INPUT_CHARS, '3');
                break;
            case 'duplicate_attempt':
                $this->assertTrue($quota->reserve($this->ana, 't.one', 'x', 'k1', 60.0)->ok());
                break;
            case 'too_many_running':
                $this->setting(AiQuota::MAX_CONCURRENT, '1');
                $this->assertTrue($quota->reserve($this->ana, 't.one', 'x', 'other', 60.0)->ok());
                break;
            case 'quota':
                $this->setting(AiQuota::REQUESTS_PER_HOUR, '1');
                $this->assertNull($quota->consume($this->ana, 't.one', 'x'));
                break;
            case 'unavailable':
                // The count insert fails, so reserve() refuses rather than let an uncounted request go.
                $this->container['db'] = new class($real) {
                    public function __construct(private $db) {}
                    public function table($name)
                    {
                        if ($name !== AiQuota::TABLE) {
                            return $this->db->table($name);
                        }
                        return new class($this->db, $name) extends \PicoDb\Table {
                            public function insert(array $data)
                            {
                                return false;
                            }
                        };
                    }
                    public function __call($method, $args)
                    {
                        return $this->db->$method(...$args);
                    }
                };
                break;
            case 'not_signed_in':
                $this->signIn(999999);
                break;
        }
        $requestsBefore = $this->counted();
        $attemptsBefore = $this->attempts();

        try {
            $events = $this->drain($this->request(), quota: $code === 'unavailable' ? new AiQuota($this->container) : null);
        } finally {
            $this->container['db'] = $real;
        }

        $refused = $this->assertRefused($events, $code, $expected);
        if ($code === 'too_long') {
            $this->assertStringContainsString('3', $refused->message, "AiQuota's own message");
        }
        if ($code === 'quota') {
            $this->assertStringContainsString('1 requests per hour', $refused->message, "AiQuota's own message");
        }
        $this->assertSame($requestsBefore, $this->counted(), 'no count added');
        $this->assertSame($attemptsBefore, $this->attempts(), 'no attempt added');
    }

    public function testCancelledBeforeReserveIsRefusedAndUncharged(): void
    {
        $cancel = new class implements AiCancellation {
            public function isCancelled(): bool
            {
                return true;
            }
        };
        $this->assertRefused($this->drain($this->request(cancel: $cancel)), 'cancelled', 'The AI request was cancelled.');
        $this->assertUncharged();
    }

    public function testStartedCarriesTheConfiguredModelAfterReserve(): void
    {
        $this->provider = new ScriptedProvider([new \CarmeloSantana\PHPAgents\Provider\Stream\Finish(\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop)]);
        $events = $this->drain($this->request());
        $this->assertCount(2, $events);
        $this->assertInstanceOf(Started::class, $events[0]);
        $this->assertSame('model-a', $events[0]->model);
        $this->assertTrue($events[0]->streaming, 'an AbstractProvider streams');
        $this->assertCount(1, $this->provider->calls, 'the request goes after the reservation');

        $this->assertSame(1, $this->counted(), 'the reservation is charged');
        $row = $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->findOne();
        $this->assertSame($this->ana, (int) $row['user_id']);
        $this->assertSame('t.one', $row['feature']);
        $this->assertSame('k1', $row['attempt_key']);
        $this->assertSame('finished', $row['status']);
    }

    public function testConfiguredModelIsResolvedBeforeTheReservation(): void
    {
        // A profile store that fails once the provider is built: the model lookup must run
        // before reserve(), so a throw there leaves nothing charged and no attempt open.
        $registry = new class($this->container) extends ProviderRegistry {
            private bool $built = false;
            public function buildProvider(?string $profileId = null): \CarmeloSantana\PHPAgents\Contract\ProviderInterface
            {
                $provider = parent::buildProvider($profileId);
                $this->built = true;
                return $provider;
            }
            public function findProfile(string $id): ?array
            {
                if ($this->built) {
                    throw new \RuntimeException('profile store failed');
                }
                return parent::findProfile($id);
            }
        };
        $registry->setProviderForTesting($this->provider);
        $stream = new AiStream($this->container);
        $stream->setRegistryForTesting($registry);

        try {
            iterator_to_array($stream->events($this->request()), false);
            $this->fail('the store failure propagates');
        } catch (\RuntimeException $e) {
            $this->assertSame('profile store failed', $e->getMessage());
        }
        $this->assertSame([], $this->provider->calls, 'no provider call');
        $this->assertUncharged();
    }

    public function testRoutingRunsFreshForEveryRequest(): void
    {
        $meta = $this->container['projectMetadataModel'];
        $meta->save($this->project, [ModelRouting::PROJECT_PROFILE => 'B']);
        $first = $this->drain($this->request(key: 'k1', project: $this->project));
        $meta->save($this->project, [ModelRouting::PROJECT_PROFILE => 'C']);
        $second = $this->drain($this->request(key: 'k2', project: $this->project));

        $this->assertInstanceOf(Started::class, $first[0]);
        $this->assertSame('model-b', $first[0]->model);
        $this->assertInstanceOf(Started::class, $second[0]);
        $this->assertSame(ProviderRegistry::DEFAULT_MODELS['ollama'], $second[0]->model, 'no model of its own: the provider default');
    }

    public function testUserIdComesFromTheSession(): void
    {
        $this->signIn($this->bob);
        $this->assertInstanceOf(Started::class, $this->drain($this->request())[0]);

        $quota = new AiQuota($this->container);
        $this->assertSame(1, $quota->usedThisHour($this->bob));
        $this->assertSame(0, $quota->usedThisHour($this->ana));
        $this->assertSame($this->bob, (int) $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->findOneColumn('user_id'));
    }

    public function testUserIsBoundWhenEventsIsCalledNotWhenIterated(): void
    {
        $registry = new ProviderRegistry($this->container);
        $registry->setProviderForTesting($this->provider);
        $stream = new AiStream($this->container);
        $stream->setRegistryForTesting($registry);

        $built = $stream->events($this->request(key: 'k1')); // built for ana
        $this->signIn($this->bob);                            // the session changes before iteration
        $this->assertInstanceOf(Started::class, iterator_to_array($built, false)[0]);
        $quota = new AiQuota($this->container);
        $this->assertSame(1, $quota->usedThisHour($this->ana), 'charged to the person who asked');
        $this->assertSame(0, $quota->usedThisHour($this->bob));
        $this->assertSame($this->ana, (int) $this->container['db']->table(AiQuota::ATTEMPTS_TABLE)->findOneColumn('user_id'));

        $this->provider->calls = [];
        unset($_SESSION['user']);
        $anonymous = $stream->events($this->request(key: 'k2')); // built with nobody signed in
        $this->signIn($this->ana);
        $this->assertRefused(iterator_to_array($anonymous, false), 'not_signed_in');
        $this->assertSame(1, $quota->usedThisHour($this->ana), 'nothing more counted');
    }
}
