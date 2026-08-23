<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ModelCatalog;
use Kanboard\Plugin\AiConnector\Model\ModelContextTable;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/** Task 2 — ModelCatalog counting, budget, resolution, cache, memo. No network. */
class ModelCatalogTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    private function seed(string $option, string $value): void
    {
        $db = $this->container['db'];
        if ($db->table('settings')->eq('option', $option)->count() > 0) {
            $db->table('settings')->eq('option', $option)->update(['value' => $value]);
        } else {
            $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        }
        $this->container['memoryCache']->flush();
    }

    private function seedProfile(string $provider, string $model, string $baseUrl = ''): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'P', 'provider' => $provider, 'model' => $model, 'base_url' => $baseUrl],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
    }

    private function catalog(): ModelCatalog
    {
        return new ModelCatalog($this->container);
    }

    // ── counting ──────────────────────────────────────────────────────────────

    public function testCountTokensHeuristicForOpenAi(): void
    {
        $this->seedProfile('openai', 'gpt-4o');
        // 40 chars / 4.0 = 10
        $this->assertSame(10, $this->catalog()->countTokens(str_repeat('a', 40)));
    }

    public function testCountTokensHeuristicForAnthropicUsesFinerRatio(): void
    {
        $this->seedProfile('anthropic', 'claude-sonnet-4');
        // 35 chars / 3.5 = 10
        $this->assertSame(10, $this->catalog()->countTokens(str_repeat('a', 35)));
    }

    public function testCountTokensDefaultsWhenNoProfile(): void
    {
        // 40 chars / 4.0 = 10, no profile configured -> default counter, no throw
        $this->assertSame(10, $this->catalog()->countTokens(str_repeat('a', 40)));
    }

    // ── getContextWindow: table + default paths ─────────────────────────────────

    public function testContextWindowFromTableForCloudProvider(): void
    {
        $this->seedProfile('anthropic', 'claude-opus-5');
        $this->assertSame(1000000, $this->catalog()->getContextWindow());
    }

    public function testContextWindowUsesProviderDefaultModelWhenModelBlank(): void
    {
        // blank model -> ProviderRegistry::DEFAULT_MODELS['openai'] = gpt-4o -> 128000
        $this->seedProfile('openai', '');
        $this->assertSame(128000, $this->catalog()->getContextWindow());
    }

    public function testContextWindowGlobalDefaultWhenNoProfile(): void
    {
        $this->assertSame(ModelContextTable::GLOBAL_DEFAULT, $this->catalog()->getContextWindow());
        $this->assertSame(8192, $this->catalog()->getContextWindow('ghost'));
    }

    // ── live resolver seam ──────────────────────────────────────────────────────

    public function testContextWindowUsesInjectedLiveResolverForOllama(): void
    {
        $this->seedProfile('ollama', 'my-custom-model');
        $c = $this->catalog();
        $c->setContextResolverForTesting(fn(array $profile): ?int => 4321);
        $this->assertSame(4321, $c->getContextWindow());
    }

    public function testOllamaFallsToTableWhenLiveReturnsNull(): void
    {
        $this->seedProfile('ollama', 'llama3.1:8b');
        $c = $this->catalog();
        $c->setContextResolverForTesting(fn(array $profile): ?int => null);
        $this->assertSame(131072, $c->getContextWindow());
    }

    public function testCloudProviderNeverCallsLiveResolver(): void
    {
        $this->seedProfile('anthropic', 'claude-opus-5');
        $c = $this->catalog();
        $c->setContextResolverForTesting(function (array $profile): ?int {
            throw new \RuntimeException('resolver must not run for cloud providers');
        });
        $this->assertSame(1000000, $c->getContextWindow());
    }

    // ── caching ─────────────────────────────────────────────────────────────────

    public function testLiveResultIsPersistedToCache(): void
    {
        $this->seedProfile('ollama', 'my-custom-model');
        $c = $this->catalog();
        $c->setContextResolverForTesting(fn(array $profile): ?int => 4321);
        $c->getContextWindow();

        $raw = $this->container['configModel']->get(ModelCatalog::CACHE_KEY, '');
        $map = json_decode($raw, true);
        $this->assertSame(4321, $map['p1|my-custom-model']['context_window']);
        $this->assertSame('live', $map['p1|my-custom-model']['source']);
    }

    public function testFreshCacheShortCircuitsLiveResolver(): void
    {
        $this->seedProfile('ollama', 'cached-model');
        $entry = ['p1|cached-model' => ['context_window' => 5555, 'source' => 'live', 'verified_at' => time()]];
        $this->seed(ModelCatalog::CACHE_KEY, json_encode($entry));

        $c = $this->catalog();
        $c->setContextResolverForTesting(function (array $profile): ?int {
            throw new \RuntimeException('fresh cache must short-circuit live discovery');
        });
        $this->assertSame(5555, $c->getContextWindow());
    }

    public function testStaleCacheIsIgnored(): void
    {
        $this->seedProfile('ollama', 'cached-model');
        $stale = ['p1|cached-model' => ['context_window' => 5555, 'source' => 'live', 'verified_at' => time() - (ModelCatalog::CACHE_TTL + 10)]];
        $this->seed(ModelCatalog::CACHE_KEY, json_encode($stale));

        $c = $this->catalog();
        $c->setContextResolverForTesting(fn(array $profile): ?int => 6789);
        $this->assertSame(6789, $c->getContextWindow());
    }

    // ── getInputBudget ──────────────────────────────────────────────────────────

    public function testInputBudgetReservesOutputHeadroom(): void
    {
        $this->seedProfile('ollama', 'gemma2:2b'); // table 8192
        $this->assertSame(7168, $this->catalog()->getInputBudget()); // 8192 - 1024
    }

    public function testInputBudgetCustomReserve(): void
    {
        $this->seedProfile('ollama', 'gemma2:2b'); // 8192
        $this->assertSame(6192, $this->catalog()->getInputBudget(null, 2000));
    }

    public function testInputBudgetClampsReserveToHalfWindow(): void
    {
        $this->seedProfile('ollama', 'nomic-embed-text'); // table 2048
        // reserve 4096 would go negative; clamped to half (1024) -> 1024
        $this->assertSame(1024, $this->catalog()->getInputBudget(null, 4096));
    }

    // ── live parse (Task 3) ─────────────────────────────────────────────────────

    public function testExtractOllamaContextReadsArchPrefixedKey(): void
    {
        $c = new class ($this->container) extends ModelCatalog {
            public function pub(array $p): ?int { return $this->extractOllamaContext($p); }
        };
        $payload = [
            'model_info' => [
                'general.architecture' => 'llama',
                'llama.context_length' => 131072,
            ],
        ];
        $this->assertSame(131072, $c->pub($payload));
    }

    public function testExtractOllamaContextHandlesNumericString(): void
    {
        $c = new class ($this->container) extends ModelCatalog {
            public function pub(array $p): ?int { return $this->extractOllamaContext($p); }
        };
        $payload = [
            'model_info' => [
                'general.architecture' => 'qwen3',
                'qwen3.context_length' => '40960',
            ],
        ];
        $this->assertSame(40960, $c->pub($payload));
    }

    public function testExtractOllamaContextReturnsNullWhenMissing(): void
    {
        $c = new class ($this->container) extends ModelCatalog {
            public function pub(array $p): ?int { return $this->extractOllamaContext($p); }
        };
        $this->assertNull($c->pub([]));
        $this->assertNull($c->pub(['model_info' => ['general.architecture' => 'llama']]));
        $this->assertNull($c->pub(['model_info' => ['general.architecture' => 'llama', 'llama.context_length' => 'NaN']]));
    }
}
