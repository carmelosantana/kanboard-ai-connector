<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ModelRouting;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/**
 * resolve(): pick > project > feature > plugin > global, locks, server-side pick checks and
 * fallback past deleted or not-ready profiles.
 */
class ResolveTest extends Base
{
    /** @var list<string> */
    public array $logged = [];
    private int $project;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'XAI_API_KEY', 'GEMINI_API_KEY', 'MISTRAL_API_KEY'] as $v) {
            putenv($v . '=');
        }
        $test = $this;
        $this->container['logger'] = new class($test) extends \Psr\Log\AbstractLogger {
            public function __construct(private $test) {}
            public function log($level, $message, array $context = []): void
            {
                $this->test->logged[] = (string) $message;
            }
        };

        // A, B, C are keyless (ready); D needs a key it does not have. A is the default.
        $profile = fn (string $id, bool $available) => ['id' => $id, 'label' => $id, 'provider' => $id === 'D' ? 'anthropic' : 'ollama', 'model' => 'm', 'base_url' => '', 'available' => $available];
        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode([$profile('A', false), $profile('B', true), $profile('C', true), $profile('D', true)]));
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'A');

        $this->container['hook']->on(ProviderRegistry::FEATURES_HOOK, fn () => [['id' => 't.one', 'label' => 'One', 'plugin' => 'T']]);
        $this->project = (int) $this->container['projectModel']->create(['name' => 'Routed']);
    }

    private function setting(string $option, string $value): void
    {
        $db = $this->container['db'];
        $db->table('settings')->eq('option', $option)->remove();
        $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        $this->container['memoryCache']->flush();
    }

    private function route(array $plugins = [], array $features = [], array $locked = []): void
    {
        $this->setting(ModelRouting::CONFIG_KEY, json_encode(['plugins' => $plugins, 'features' => $features, 'locked' => $locked]));
    }

    private function projectProfile(string $profile, bool $switch = false): void
    {
        $this->container['projectMetadataModel']->save($this->project, [
            ModelRouting::PROJECT_PROFILE => $profile,
            ModelRouting::MEMBERS_SWITCH => $switch ? '1' : '0',
        ]);
    }

    private function resolve(string $feature = 't.one', ?int $project = null, ?string $pick = null): string
    {
        return (new ProviderRegistry($this->container))->resolve($feature, $project, $pick);
    }

    public function testNothingSetUsesTheGlobalDefault(): void
    {
        $this->assertSame('A', $this->resolve());
    }

    public function testPluginAssignment(): void
    {
        $this->route(['T' => 'B']);
        $this->assertSame('B', $this->resolve());
    }

    public function testFeatureBeatsPlugin(): void
    {
        $this->route(['T' => 'B'], ['t.one' => 'C']);
        $this->assertSame('C', $this->resolve());
    }

    public function testProjectBeatsFeature(): void
    {
        $this->route([], ['t.one' => 'C']);
        $this->projectProfile('B');
        $this->assertSame('B', $this->resolve('t.one', $this->project));
    }

    public function testPickWinsWhenTheProjectAllowsSwitching(): void
    {
        $this->projectProfile('B', true);
        $this->assertSame('C', $this->resolve('t.one', $this->project, 'C'));
    }

    public function testPickIgnoredWhenTheProjectDoesNotAllowSwitching(): void
    {
        $this->projectProfile('B', false);
        $this->assertSame('B', $this->resolve('t.one', $this->project, 'C'));
    }

    public function testAForgedPickForAProfileNotOfferedIsIgnored(): void
    {
        // E exists and is ready but is not available to users.
        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'A', 'label' => 'A', 'provider' => 'ollama', 'model' => 'm', 'base_url' => ''],
            ['id' => 'B', 'label' => 'B', 'provider' => 'ollama', 'model' => 'm', 'base_url' => '', 'available' => true],
            ['id' => 'E', 'label' => 'E', 'provider' => 'ollama', 'model' => 'm', 'base_url' => ''],
        ]));
        $this->assertSame('A', $this->resolve('t.one', null, 'E'));
        $this->assertSame('A', $this->resolve('t.one', null, 'ghost'));
    }

    public function testALockedFeatureIgnoresPickAndProject(): void
    {
        $this->route([], ['t.one' => 'C'], ['t.one']);
        $this->projectProfile('B', true);
        $this->assertSame('C', $this->resolve('t.one', $this->project, 'A'));
    }

    public function testALockedFeatureWithABrokenAssignmentFallsToPluginThenGlobalNeverProject(): void
    {
        $this->projectProfile('C', true);
        $this->route(['T' => 'B'], ['t.one' => 'D'], ['t.one']);
        $this->assertSame('B', $this->resolve('t.one', $this->project, 'C'));

        $this->route([], ['t.one' => 'D'], ['t.one']);
        $this->assertSame('A', $this->resolve('t.one', $this->project, 'C'));
    }

    public function testADeletedProfileFallsBackAndIsLogged(): void
    {
        $this->route(['T' => 'B'], ['t.one' => 'zz']);
        $this->assertSame('B', $this->resolve());
        $this->assertContains('[AiConnector] routing: t.one skipped feature profile zz (missing)', $this->logged);
    }

    public function testANotReadyProfileFallsBackAndIsLogged(): void
    {
        $this->route([], ['t.one' => 'D']);
        $this->assertSame('A', $this->resolve());
        $this->assertContains('[AiConnector] routing: t.one skipped feature profile D (not ready)', $this->logged);
    }

    public function testASkipIsLoggedOncePerRegistry(): void
    {
        $this->route([], ['t.one' => 'D']);
        $registry = new ProviderRegistry($this->container);
        $registry->resolve('t.one');
        $registry->resolve('t.one');
        $this->assertCount(1, array_filter($this->logged, fn ($m) => str_contains($m, 'skipped feature profile D')));
    }

    public function testAnUnregisteredFeatureUsesProjectThenGlobal(): void
    {
        $this->route(['T' => 'C'], ['x.y' => 'C']);
        $this->projectProfile('B');
        $this->assertSame('B', $this->resolve('x.y', $this->project));
        $this->assertSame('A', $this->resolve('x.y'));
    }

    public function testNoReadyProfileAnywhereResolvesToEmpty(): void
    {
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'D');
        $this->route([], ['t.one' => 'D']);
        $this->assertSame('', $this->resolve());
    }

    public function testWithoutAProjectAnOfferedPickWins(): void
    {
        $this->route(['T' => 'B']);
        $this->assertSame('C', $this->resolve('t.one', null, 'C'));
    }

    public function testANotReadyPickIsIgnored(): void
    {
        $this->assertSame('A', $this->resolve('t.one', null, 'D'));
    }
}
