# ModelCatalog — Context-Window & Token API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a public PHP API to AiConnector for discovering a model's context window and estimating token counts, so sibling plugins (TimeInvoice) can fit prompts to any configured model.

**Architecture:** A new `Model/ModelCatalog` (extends `Kanboard\Core\Base`, constructed `new ModelCatalog($container)` like `ProviderRegistry`) exposes `getContextWindow()`, `countTokens()`, `getInputBudget()`. Context resolution is: in-request memo → persisted `configModel` cache (24h, model-keyed) → live Ollama `/api/show` (Ollama profiles only) → curated `Model/ModelContextTable` (exact id → normalized id → longest-prefix family → provider default) → global default 8192. Token counting reuses php-agents `TokenCounterFactory` (heuristic-only; no tiktoken dependency). All methods are non-throwing.

**Tech Stack:** Plain PHP ≥ 8.4 (buildless — committed tree ships), php-agents (vendored: `Context\TokenCounterFactory`, `Config\ModelDefinition`), Symfony HttpClient (vendored), PHPUnit via Kanboard's `KanboardTests\units\Base`.

## Global Constraints

- **Buildless.** No npm/build step; no new Composer dependency. `yethee/tiktoken-php` is NOT added. The committed `vendor/` is what ships.
- **PHP ≥ 8.4**, Kanboard ≥ 1.2.47.
- **Secrets discipline.** Never log, or place in a message/exception, any API key. `ModelCatalog` only touches the keyless Ollama live path; it never handles keys.
- **Non-throwing public API.** `getContextWindow`, `countTokens`, `getInputBudget` must never throw — unknown/missing profile, unreachable Ollama, unknown model → conservative fallback.
- **No `ProviderRegistry.php` changes.** It is read/consumed, not modified.
- **Test seam, no network in unit tests.** `setContextResolverForTesting(callable)` stubs live discovery, mirroring `ProviderRegistry::setProviderForTesting()`.
- **Value convention.** Table stores each model's total native context window (same quantity live Ollama reports). Ollama family values use the conservative floor. Beta-only tiers excluded (Claude Sonnet 4/4.5 = 200K standard).
- **Version alignment.** `plugin.json` `version`, `Plugin.php::getPluginVersion()`, and the release tag must all read `1.1.0`.
- **Test command (from monorepo root** `/home/carmelo/Projects/Kanboard/kanboard-plugins`**):** `./testing/run-plugin-tests.sh AiConnector`
- **Spec:** `docs/superpowers/specs/2026-08-23-model-context-token-api-design.md` (§8 carries the full cited table data; `LAST_VERIFIED = '2026-08-23'`).

---

## File Structure

- `Model/ModelContextTable.php` — **new.** Pure data + resolver. `LAST_VERIFIED`, `GLOBAL_DEFAULT`, per-provider exact/family/default maps, `resolve(provider, model): int`. No container, no php-agents.
- `Model/ModelCatalog.php` — **new.** Public API + resolution/cache/memo/counting + live-discovery seam.
- `Test/ModelContextTableTest.php` — **new.** Pure unit tests for the table resolver.
- `Test/ModelCatalogTest.php` — **new.** Container-based tests (counting, resolution order, cache, budget, no-throw) using the injected resolver — no network.
- `plugin.json`, `Plugin.php`, `README.md`, `CHANGELOG.md` — **modify.** v1.1.0 + docs.

---

### Task 1: ModelContextTable (curated data + resolver)

**Files:**
- Create: `Model/ModelContextTable.php`
- Test: `Test/ModelContextTableTest.php`

**Interfaces:**
- Consumes: nothing (pure).
- Produces:
  - `ModelContextTable::LAST_VERIFIED` (string `'2026-08-23'`)
  - `ModelContextTable::GLOBAL_DEFAULT` (int `8192`)
  - `ModelContextTable::resolve(string $provider, string $model): int` — provider is a `ProviderRegistry` provider type (`anthropic|openai|openai_responses|grok|gemini|mistral|ollama`).

- [ ] **Step 1: Write the failing test**

Create `Test/ModelContextTableTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ModelContextTable;
use KanboardTests\units\Base;

/** Task 1 — curated context-window table resolver. Pure, no network, no DB. */
class ModelContextTableTest extends Base
{
    public function testExactIdMatch(): void
    {
        $this->assertSame(128000, ModelContextTable::resolve('openai', 'gpt-4o'));
        $this->assertSame(1047576, ModelContextTable::resolve('openai', 'gpt-4.1-mini'));
        $this->assertSame(1050000, ModelContextTable::resolve('openai', 'gpt-5.6-sol'));
        $this->assertSame(400000, ModelContextTable::resolve('openai', 'gpt-5.6-cyber'));
        $this->assertSame(1000000, ModelContextTable::resolve('anthropic', 'claude-opus-5'));
    }

    public function testOpenAiResponsesSharesOpenAiPartition(): void
    {
        $this->assertSame(400000, ModelContextTable::resolve('openai_responses', 'gpt-5'));
    }

    public function testDatedAnthropicIdNormalizesToExact(): void
    {
        $this->assertSame(200000, ModelContextTable::resolve('anthropic', 'claude-sonnet-4-20250514'));
        $this->assertSame(200000, ModelContextTable::resolve('anthropic', 'claude-opus-4-1-20250805'));
    }

    public function testLongestPrefixFamilyWins(): void
    {
        // gpt-5.6 (1.05M) must beat gpt-5 (400k) for an unknown gpt-5.6-* id
        $this->assertSame(1050000, ModelContextTable::resolve('openai', 'gpt-5.6-experimental'));
        // grok-4-fast (2M) must beat grok-4 (256k)
        $this->assertSame(2000000, ModelContextTable::resolve('grok', 'grok-4-fast-reasoning'));
        $this->assertSame(256000, ModelContextTable::resolve('grok', 'grok-4-0709'));
    }

    public function testOllamaTagStrippedThenFamilyMatched(): void
    {
        $this->assertSame(131072, ModelContextTable::resolve('ollama', 'llama3.1:8b-instruct-q4_K_M'));
        $this->assertSame(8192, ModelContextTable::resolve('ollama', 'gemma2:2b'));
        $this->assertSame(32768, ModelContextTable::resolve('ollama', 'qwen2.5-coder:7b'));
        $this->assertSame(40960, ModelContextTable::resolve('ollama', 'qwen3'));
    }

    public function testProviderDefaultWhenUnknownId(): void
    {
        $this->assertSame(200000, ModelContextTable::resolve('anthropic', 'totally-unknown'));
        $this->assertSame(128000, ModelContextTable::resolve('openai', 'totally-unknown'));
        $this->assertSame(1048576, ModelContextTable::resolve('gemini', 'totally-unknown'));
        $this->assertSame(128000, ModelContextTable::resolve('mistral', 'totally-unknown'));
        $this->assertSame(131072, ModelContextTable::resolve('grok', 'totally-unknown'));
        $this->assertSame(8192, ModelContextTable::resolve('ollama', 'totally-unknown'));
    }

    public function testGlobalDefaultForUnknownProvider(): void
    {
        $this->assertSame(8192, ModelContextTable::resolve('llamacpp', 'whatever'));
        $this->assertSame(8192, ModelContextTable::GLOBAL_DEFAULT);
    }

    public function testLastVerifiedPresent(): void
    {
        $this->assertSame('2026-08-23', ModelContextTable::LAST_VERIFIED);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (from monorepo root): `./testing/run-plugin-tests.sh AiConnector`
Expected: FAIL — `Class "Kanboard\Plugin\AiConnector\Model\ModelContextTable" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `Model/ModelContextTable.php`:

```php
<?php

namespace Kanboard\Plugin\AiConnector\Model;

/**
 * ModelContextTable — curated fallback map of model id/family -> max context window.
 *
 * Pure data + resolver. No container, no php-agents, no network. Used by
 * ModelCatalog when live discovery is unavailable/not applicable.
 *
 * Values are each model's TOTAL native context window (the same quantity live
 * Ollama /api/show reports), verified against primary sources on LAST_VERIFIED.
 * Ollama families use the conservative floor (under-reporting only makes budgeting
 * safer). Beta-only tiers are excluded (Claude Sonnet 4/4.5 = 200K standard).
 *
 * See docs/superpowers/specs/2026-08-23-model-context-token-api-design.md §8 for
 * the cited sources behind every value.
 *
 * @package Kanboard\Plugin\AiConnector\Model
 * @author  Carmelo Santana
 */
final class ModelContextTable
{
    /** Date every value below was verified against a primary source. */
    public const LAST_VERIFIED = '2026-08-23';

    /** Returned when provider is unknown/unsupported and nothing else matches. */
    public const GLOBAL_DEFAULT = 8192;

    /** ProviderRegistry provider type -> table partition. openai_responses shares openai. */
    private const PARTITION = [
        'openai_responses' => 'openai',
    ];

    /** Exact model id (lowercased) -> context window, per partition. */
    private const EXACT = [
        'anthropic' => [
            'claude-3-opus'        => 200000,
            'claude-3-5-sonnet'    => 200000,
            'claude-3-5-haiku'     => 200000,
            'claude-sonnet-4'      => 200000,
            'claude-opus-4'        => 200000,
            'claude-opus-4-1'      => 200000,
            'claude-sonnet-4-5'    => 200000,
            'claude-opus-4-5'      => 200000,
            'claude-haiku-4-5'     => 200000,
            'claude-opus-5'        => 1000000,
            'claude-sonnet-5'      => 1000000,
            'claude-fable-5'       => 1000000,
            'claude-mythos-5'      => 1000000,
            'claude-mythos-preview'=> 1000000,
        ],
        'openai' => [
            'gpt-4o'            => 128000,
            'gpt-4o-mini'       => 128000,
            'gpt-4.1'           => 1047576,
            'gpt-4.1-mini'      => 1047576,
            'gpt-4.1-nano'      => 1047576,
            'gpt-5'             => 400000,
            'gpt-5-mini'        => 400000,
            'gpt-5-nano'        => 400000,
            'gpt-5-pro'         => 400000,
            'gpt-5-chat-latest' => 128000,
            'gpt-5.6'           => 1050000,
            'gpt-5.6-sol'       => 1050000,
            'gpt-5.6-terra'     => 1050000,
            'gpt-5.6-luna'      => 1050000,
            'gpt-5.6-cyber'     => 400000,
            'o1'                => 200000,
            'o1-mini'           => 128000,
            'o1-pro'            => 200000,
            'o3'                => 200000,
            'o3-mini'           => 200000,
            'o3-pro'            => 200000,
            'o4-mini'           => 200000,
        ],
        'gemini' => [
            'gemini-1.5-pro'         => 2097152,
            'gemini-1.5-flash'       => 1048576,
            'gemini-2.0-flash'       => 1048576,
            'gemini-2.0-flash-lite'  => 1048576,
            'gemini-2.5-pro'         => 1048576,
            'gemini-2.5-flash'       => 1048576,
            'gemini-2.5-flash-lite'  => 1048576,
        ],
        'mistral' => [
            'mistral-small-2409'  => 32000,
            'mistral-medium-2508' => 128000,
            'mistral-medium-2604' => 256000,
        ],
        'grok' => [
            'grok-4'                    => 256000,
            'grok-4-latest'             => 256000,
            'grok-4-0709'               => 256000,
            'grok-code-fast-1'          => 256000,
            'grok-4-fast-reasoning'     => 2000000,
            'grok-4-fast-non-reasoning' => 2000000,
        ],
    ];

    /**
     * Family prefix (lowercased) -> context window, per partition.
     * Matched longest-prefix-first, so a more specific prefix always wins.
     */
    private const FAMILY = [
        'anthropic' => [
            'claude-3'          => 200000,
            'claude-sonnet-4-6' => 1000000,
            'claude-sonnet-4-7' => 1000000,
            'claude-sonnet-4-8' => 1000000,
            'claude-opus-4-6'   => 1000000,
            'claude-opus-4-7'   => 1000000,
            'claude-opus-4-8'   => 1000000,
            'claude-sonnet-4'   => 200000,
            'claude-opus-4'     => 200000,
            'claude-haiku-4'    => 200000,
            'claude-opus-5'     => 1000000,
            'claude-sonnet-5'   => 1000000,
            'claude-fable-5'    => 1000000,
            'claude-mythos'     => 1000000,
        ],
        'openai' => [
            'gpt-5.6' => 1050000,
            'gpt-5'   => 400000,
            'gpt-4.1' => 1047576,
            'gpt-4o'  => 128000,
            'o1'      => 200000,
            'o3'      => 200000,
            'o4'      => 200000,
        ],
        'gemini' => [
            'gemini-1.5-pro' => 2097152,
            'gemini'         => 1048576,
        ],
        'mistral' => [
            'mistral-large'  => 128000,
            'mistral-small'  => 128000,
            'mistral-medium' => 128000,
            'codestral'      => 128000,
            'ministral'      => 128000,
            'pixtral'        => 128000,
        ],
        'grok' => [
            'grok-4-fast' => 2000000,
            'grok-4'      => 256000,
            'grok-code'   => 256000,
            'grok-3'      => 131072,
            'grok-2'      => 131072,
        ],
        'ollama' => [
            'qwen3'           => 40960,
            'qwen2.5-coder'   => 32768,
            'qwen2.5'         => 32768,
            'llama3.1'        => 131072,
            'llama3.2'        => 131072,
            'llama3.3'        => 131072,
            'gemma2'          => 8192,
            'gemma3'          => 32768,
            'mixtral'         => 32768,
            'mistral'         => 32768,
            'phi3'            => 4096,
            'phi4'            => 16384,
            'deepseek-r1'     => 131072,
            'deepseek-v3'     => 131072,
            'codellama'       => 16384,
            'nomic-embed-text'=> 2048,
        ],
    ];

    /** Conservative per-partition default when no id/family matches. */
    private const PROVIDER_DEFAULT = [
        'anthropic' => 200000,
        'openai'    => 128000,
        'gemini'    => 1048576,
        'mistral'   => 128000,
        'grok'      => 131072,
        'ollama'    => 8192,
    ];

    /**
     * Resolve a max context window for a provider type + model id.
     * Order: exact(raw) -> exact(normalized) -> longest-prefix family ->
     * provider default -> global default. Never throws.
     */
    public static function resolve(string $provider, string $model): int
    {
        $partition = self::PARTITION[$provider] ?? $provider;
        $lower     = strtolower(trim($model));

        $exact = self::EXACT[$partition] ?? [];
        if ($lower !== '' && isset($exact[$lower])) {
            return $exact[$lower];
        }

        $candidate = self::normalizeCandidate($partition, $lower);
        if ($candidate !== '' && isset($exact[$candidate])) {
            return $exact[$candidate];
        }

        $families = self::FAMILY[$partition] ?? [];
        $bestKey  = null;
        foreach ($families as $key => $window) {
            if ($candidate !== '' && str_starts_with($candidate, $key)) {
                if ($bestKey === null || strlen($key) > strlen($bestKey)) {
                    $bestKey = $key;
                }
            }
        }
        if ($bestKey !== null) {
            return $families[$bestKey];
        }

        return self::PROVIDER_DEFAULT[$partition] ?? self::GLOBAL_DEFAULT;
    }

    /**
     * Reduce a concrete id to a family-matchable candidate.
     * Ollama: drop the ":tag" suffix. Others: strip a trailing -latest,
     * -0, or -<8-digit date>.
     */
    private static function normalizeCandidate(string $partition, string $lower): string
    {
        if ($lower === '') {
            return '';
        }
        if ($partition === 'ollama') {
            $pos = strpos($lower, ':');
            return $pos === false ? $lower : substr($lower, 0, $pos);
        }
        $c = preg_replace('/-(latest|\d{8})$/', '', $lower);
        $c = preg_replace('/-0$/', '', (string) $c);
        return (string) $c;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS — all `ModelContextTableTest` methods green.

- [ ] **Step 5: Commit**

```bash
git add Model/ModelContextTable.php Test/ModelContextTableTest.php
git commit -m "feat(catalog): curated model context-window table + resolver"
```

---

### Task 2: ModelCatalog — counting, budget, resolution, cache, memo

**Files:**
- Create: `Model/ModelCatalog.php`
- Test: `Test/ModelCatalogTest.php`

**Interfaces:**
- Consumes: `ModelContextTable::resolve()`, `ModelContextTable::GLOBAL_DEFAULT` (Task 1); `ProviderRegistry` (`getDefaultProfileId()`, `findProfile()`, `DEFAULT_MODELS`, `PROVIDER_*` consts); php-agents `CarmeloSantana\PHPAgents\Context\TokenCounterFactory::forModel(string): TokenCounterInterface` (has `count(string): int`).
- Produces (public surface — the locked TimeInvoice contract):
  - `getContextWindow(?string $profileId = null): int`
  - `countTokens(string $text, ?string $profileId = null): int`
  - `getInputBudget(?string $profileId = null, int $reserveOutputTokens = 1024): int`
  - `setContextResolverForTesting(callable $fn): void` where `$fn(array $profile): ?int`
  - Consts: `ModelCatalog::CACHE_KEY = 'aiconnector_ctx_cache'`, `CACHE_TTL = 86400`, `DEFAULT_RESERVE = 1024`
  - Protected `resolveLive(array $profile): ?int` (delegates to the injected resolver, else `ollamaShowContext()`); `ollamaShowContext(array $profile): ?int` returns `null` in this task (real HTTP added in Task 3).

- [ ] **Step 1: Write the failing test**

Create `Test/ModelCatalogTest.php`:

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: FAIL — `Class "Kanboard\Plugin\AiConnector\Model\ModelCatalog" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `Model/ModelCatalog.php`:

```php
<?php

namespace Kanboard\Plugin\AiConnector\Model;

use Kanboard\Core\Base;

use CarmeloSantana\PHPAgents\Context\TokenCounterFactory;

/**
 * ModelCatalog — public PHP API for model context-window discovery + token
 * counting. Other plugins consume it directly: new ModelCatalog($container).
 *
 * Resolution (getContextWindow): in-request memo -> persisted configModel cache
 * (24h, keyed by profile+model) -> live Ollama /api/show (Ollama only) ->
 * curated ModelContextTable -> global default 8192.
 *
 * Token counting reuses php-agents TokenCounterFactory (heuristic-only; no
 * tiktoken dependency). All public methods are non-throwing.
 *
 * php-agents timing: TokenCounterFactory is referenced only inside method bodies
 * reached at request time, never at class-load, mirroring ProviderRegistry.
 *
 * Secrets: ModelCatalog only touches the keyless Ollama live path. It never
 * reads, logs, or emits an API key.
 *
 * @package Kanboard\Plugin\AiConnector\Model
 * @author  Carmelo Santana
 */
class ModelCatalog extends Base
{
    /** configModel key holding the JSON context-window cache. */
    public const CACHE_KEY = 'aiconnector_ctx_cache';

    /** Cache freshness window, seconds (24h). */
    public const CACHE_TTL = 86400;

    /** Default output-token headroom reserved by getInputBudget(). */
    public const DEFAULT_RESERVE = 1024;

    /** In-request memo, keyed by resolved profile id ('' -> '(default)'). */
    private array $memo = [];

    /** Test seam — fn(array $profile): ?int. When set, replaces live discovery. */
    private $contextResolver = null;

    /** Inject a live-discovery stub so unit tests make no network call. */
    public function setContextResolverForTesting(callable $fn): void
    {
        $this->contextResolver = $fn;
    }

    /**
     * Maximum context length in tokens for the profile's model.
     * null $profileId -> the default profile. Never throws.
     */
    public function getContextWindow(?string $profileId = null): int
    {
        $registry = new ProviderRegistry($this->container);
        $id       = $profileId ?? $registry->getDefaultProfileId();
        $memoKey  = $id === '' ? '(default)' : $id;

        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $profile = $id !== '' ? $registry->findProfile($id) : null;
        if ($profile === null) {
            return $this->memo[$memoKey] = ModelContextTable::GLOBAL_DEFAULT;
        }

        $provider = $profile['provider'];
        $model    = $profile['model'] !== ''
            ? $profile['model']
            : (ProviderRegistry::DEFAULT_MODELS[$provider] ?? '');

        $cacheKey = $id . '|' . $model;
        $cached   = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $this->memo[$memoKey] = $cached;
        }

        if ($provider === ProviderRegistry::PROVIDER_OLLAMA) {
            $live = $this->resolveLive($profile);
            if ($live !== null && $live > 0) {
                $this->writeCache($cacheKey, $live, 'live');
                return $this->memo[$memoKey] = $live;
            }
        }

        $window = ModelContextTable::resolve($provider, $model);
        $this->writeCache($cacheKey, $window, 'table');
        return $this->memo[$memoKey] = $window;
    }

    /**
     * Heuristic token-count estimate for $text, using a provider-appropriate
     * chars-per-token ratio (anthropic 3.5, openai/others 4.0). Never throws.
     */
    public function countTokens(string $text, ?string $profileId = null): int
    {
        $provider = $this->providerOf($profileId);
        $counter  = TokenCounterFactory::forModel($this->counterKey($provider) . '/model');
        return $counter->count($text);
    }

    /**
     * Prompt-token budget: how many input tokens fit once output headroom is
     * reserved. = max(0, getContextWindow() - reserve), with reserve clamped to
     * at most half the window (guards tiny-context local models). Never throws.
     */
    public function getInputBudget(?string $profileId = null, int $reserveOutputTokens = self::DEFAULT_RESERVE): int
    {
        $window  = $this->getContextWindow($profileId);
        $reserve = min(max(0, $reserveOutputTokens), intdiv($window, 2));
        return max(0, $window - $reserve);
    }

    // ── live discovery (seam; production HTTP added in Task 3) ───────────────────

    /** Resolve context live: injected stub if set, else the production path. */
    protected function resolveLive(array $profile): ?int
    {
        if ($this->contextResolver !== null) {
            return ($this->contextResolver)($profile);
        }
        return $this->ollamaShowContext($profile);
    }

    /** Production live Ollama discovery. Wired in Task 3; null until then. */
    protected function ollamaShowContext(array $profile): ?int
    {
        return null;
    }

    // ── private helpers ─────────────────────────────────────────────────────────

    /** Provider type for a profile id (null -> default). '' when unresolved. */
    private function providerOf(?string $profileId): string
    {
        $registry = new ProviderRegistry($this->container);
        $id       = $profileId ?? $registry->getDefaultProfileId();
        if ($id === '') {
            return '';
        }
        $profile = $registry->findProfile($id);
        return is_array($profile) ? (string) $profile['provider'] : '';
    }

    /** Map a provider type to the TokenCounterFactory provider prefix. */
    private function counterKey(string $provider): string
    {
        return match ($provider) {
            ProviderRegistry::PROVIDER_ANTHROPIC => 'anthropic',
            ProviderRegistry::PROVIDER_OPENAI,
            ProviderRegistry::PROVIDER_OPENAI_RESPONSES => 'openai',
            default => 'other',
        };
    }

    /** Read a fresh (non-stale) cached window for $cacheKey, or null. */
    private function readCache(string $cacheKey): ?int
    {
        $raw = $this->configModel->get(self::CACHE_KEY, '');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $map = json_decode($raw, true);
        if (! is_array($map) || ! isset($map[$cacheKey]) || ! is_array($map[$cacheKey])) {
            return null;
        }
        $entry = $map[$cacheKey];
        $window = (int) ($entry['context_window'] ?? 0);
        $ts     = (int) ($entry['verified_at'] ?? 0);
        if ($window <= 0 || (time() - $ts) > self::CACHE_TTL) {
            return null;
        }
        return $window;
    }

    /** Persist a resolved window. Best-effort — never breaks resolution. */
    private function writeCache(string $cacheKey, int $window, string $source): void
    {
        try {
            $raw = $this->configModel->get(self::CACHE_KEY, '');
            $map = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : [];
            if (! is_array($map)) {
                $map = [];
            }
            $map[$cacheKey] = [
                'context_window' => $window,
                'source'         => $source,
                'verified_at'    => time(),
            ];
            $this->configModel->save([self::CACHE_KEY => json_encode($map)]);
            $this->container['memoryCache']->flush();
        } catch (\Throwable $e) {
            // Persistence is best-effort; the in-request memo still applies.
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS — all `ModelCatalogTest` methods green (and Task 1 still green).

- [ ] **Step 5: Commit**

```bash
git add Model/ModelCatalog.php Test/ModelCatalogTest.php
git commit -m "feat(catalog): ModelCatalog counting, budget, resolution, cache"
```

---

### Task 3: Live Ollama discovery (parse + HTTP)

**Files:**
- Modify: `Model/ModelCatalog.php` (implement `extractOllamaContext()` and wire `ollamaShowContext()`)
- Test: `Test/ModelCatalogTest.php` (add parse-unit tests)

**Interfaces:**
- Consumes: php-agents `CarmeloSantana\PHPAgents\Config\ModelDefinition::fromDiscovery(string $provider, array $data): ?ModelDefinition` (has public readonly `int $contextWindow`); Symfony `Symfony\Component\HttpClient\HttpClient::create()` returning a client with `request('POST', $url, ['json'=>..., 'timeout'=>...])` whose response has `toArray(bool $throw = true): array`.
- Produces: `extractOllamaContext(array $showPayload): ?int` — pulls the native context length from an Ollama `/api/show` response (`model_info["<general.architecture>.context_length"]`), or null. `ollamaShowContext()` now performs one `POST {baseUrl}/api/show` and returns the parsed window (or null on any failure).

- [ ] **Step 1: Write the failing test**

Add these methods to `Test/ModelCatalogTest.php` (a `TestableCatalog` subclass exposes the protected parse method):

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: FAIL — `Call to protected method ... extractOllamaContext()` does not exist / not defined.

- [ ] **Step 3: Write minimal implementation**

In `Model/ModelCatalog.php`, add the import at the top with the other `use` statements:

```php
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
```

Replace the placeholder `ollamaShowContext()` body and add `extractOllamaContext()`:

```php
    /**
     * Production live Ollama discovery: one POST {baseUrl}/api/show, parsed to a
     * native context length. Reuses php-agents ModelDefinition::fromDiscovery for
     * normalization. Any failure (unreachable, timeout, missing field) -> null,
     * so resolution falls through to the curated table. Never throws.
     */
    protected function ollamaShowContext(array $profile): ?int
    {
        try {
            $base = (string) ($profile['base_url'] ?? '');
            if ($base === '') {
                $host = getenv('OLLAMA_HOST');
                $base = ($host !== false && $host !== '') ? $host : 'http://localhost:11434';
            }
            $base = preg_replace('#/v1/?$#', '', rtrim($base, '/'));

            $client   = \Symfony\Component\HttpClient\HttpClient::create();
            $response = $client->request('POST', $base . '/api/show', [
                'json'    => ['name' => (string) ($profile['model'] ?? '')],
                'timeout' => 5,
            ]);
            $data = $response->toArray(false);

            $ctx = $this->extractOllamaContext($data);
            if ($ctx === null || $ctx <= 0) {
                return null;
            }
            $def = ModelDefinition::fromDiscovery('ollama', [
                'id'            => (string) ($profile['model'] ?? 'ollama'),
                'contextWindow' => $ctx,
            ]);
            return $def?->contextWindow;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract the native context length from an Ollama /api/show payload:
     * model_info["<general.architecture>.context_length"]. Null when absent or
     * non-numeric. Mirrors OllamaProvider's own extraction.
     *
     * @param array<string, mixed> $showPayload
     */
    protected function extractOllamaContext(array $showPayload): ?int
    {
        $modelInfo = is_array($showPayload['model_info'] ?? null) ? $showPayload['model_info'] : [];
        $arch      = $modelInfo['general.architecture'] ?? null;
        if (! is_string($arch) || $arch === '') {
            return null;
        }
        $value = $modelInfo[$arch . '.context_length'] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS — new parse tests green; all prior tests still green.

- [ ] **Step 5: Live E2E verification (manual — the one un-unit-testable path)**

The real HTTP path can't be unit-tested offline. Verify against a running Ollama
(the dev stack or a local daemon). If no Ollama is reachable, confirm graceful
fallback instead (the command must print a table/default value, never error):

```bash
docker compose -f testing/docker-compose.dev.yml exec -T kb-suite \
  php -r '$c=require "/var/www/app/config.php"; require "/var/www/app/vendor/autoload.php";' 2>/dev/null; echo "(dev-stack sanity — see note)"
```

Note for the implementer: prefer a focused check — from a PHP context with the
container's Kanboard bootstrap, construct `new ModelCatalog($container)` against an
Ollama profile whose model is actually pulled, call `getContextWindow()`, and
confirm it returns the model's real native window (e.g. `llama3.2` → 131072) and
writes a `source: live` cache entry. With no Ollama reachable, confirm it returns
the curated-table value and does not throw. Record the observed output in the PR.

- [ ] **Step 6: Commit**

```bash
git add Model/ModelCatalog.php Test/ModelCatalogTest.php
git commit -m "feat(catalog): live Ollama /api/show context discovery"
```

---

### Task 4: Version bump + documentation

**Files:**
- Modify: `plugin.json`, `Plugin.php`, `README.md`, `CHANGELOG.md`

**Interfaces:**
- Consumes: the finished public API from Tasks 1–3.
- Produces: v1.1.0 metadata alignment + consumer-facing docs. No new code paths.

- [ ] **Step 1: Bump `plugin.json`**

Set `version` to `1.1.0` and refresh the description. Final file:

```json
{
    "name": "AiConnector",
    "description": "Universal multi-provider AI backend (php-agents) with provider profiles, model context-window discovery, and a shared PHP API.",
    "version": "1.1.0",
    "author": "Carmelo Santana",
    "homepage": "https://github.com/carmelosantana/kanboard-ai-connector",
    "license": "MIT",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4"
}
```

- [ ] **Step 2: Bump `Plugin.php`**

In `Plugin.php`, update `getPluginVersion()` and align the description string.

Change:

```php
    public function getPluginVersion(): string
    {
        return '1.0.0';
    }
```

to:

```php
    public function getPluginVersion(): string
    {
        return '1.1.0';
    }
```

And change the description getter body:

```php
        return t('Universal multi-provider AI backend (php-agents) with provider profiles and a shared PHP API.');
```

to:

```php
        return t('Universal multi-provider AI backend (php-agents) with provider profiles, model context-window discovery, and a shared PHP API.');
```

- [ ] **Step 3: Document the API in `README.md`**

Append a section (place it after the existing ProviderRegistry API documentation):

````markdown
## ModelCatalog API (v1.1.0+)

Discover a model's context window and estimate token counts. Construct it like
`ProviderRegistry`:

```php
use Kanboard\Plugin\AiConnector\Model\ModelCatalog;

$catalog = new ModelCatalog($this->container);

$max    = $catalog->getContextWindow($profileId);          // int, e.g. 131072
$tokens = $catalog->countTokens($text, $profileId);        // heuristic int estimate
$budget = $catalog->getInputBudget($profileId);            // fits-in-prompt int (reserves 1024 output)
$budget = $catalog->getInputBudget($profileId, 2048);      // custom output reserve
```

- `$profileId` is optional everywhere; `null` uses the default profile.
- **All three methods are non-throwing.** An unknown/missing profile, an
  unreachable Ollama, or an unknown model id falls back to a conservative value.
- **Context resolution:** for Ollama profiles a live `/api/show` lookup runs and
  is cached for 24h; all providers fall back to a curated table
  (`ModelContextTable`, `LAST_VERIFIED` dated) and finally a 8192 default.
- **Token counting** is heuristic (no network, no tiktoken dependency): ~4
  chars/token (3.5 for Anthropic).

Typical "fit the prompt" loop:

```php
$budget = $catalog->getInputBudget($profileId);
while ($catalog->countTokens($draft, $profileId) > $budget) {
    $draft = shrink($draft);
}
```

Consumers should treat AiConnector as an optional (`recommends`) dependency and
guard usage with `class_exists(ModelCatalog::class)`.
````

- [ ] **Step 4: Add a `CHANGELOG.md` entry**

Add at the top of the changelog (above the existing 1.0.0 entry), matching the
file's existing heading style:

```markdown
## 1.1.0

### Added
- `ModelCatalog` public API: `getContextWindow()`, `countTokens()`,
  `getInputBudget()` for model context-window discovery and token estimation.
- Live Ollama context discovery via `/api/show` with a 24h cache; curated
  fallback table (`ModelContextTable`) covering Ollama, Anthropic, OpenAI,
  Gemini, Mistral, and xAI Grok, plus a conservative 8192 default.
- `setContextResolverForTesting()` seam for network-free unit tests.
```

- [ ] **Step 5: Verify version alignment and run the full suite**

```bash
grep -H '"version"' plugin.json; grep -n "return '1.1.0'" Plugin.php
```
Expected: `plugin.json` shows `"version": "1.1.0"` and `Plugin.php` returns `'1.1.0'`.

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS — entire suite green (including the pre-existing ProviderRegistry/Settings/Plugin tests).

- [ ] **Step 6: Commit**

```bash
git add plugin.json Plugin.php README.md CHANGELOG.md
git commit -m "chore(release): AiConnector v1.1.0 — ModelCatalog context/token API"
```

---

## Post-plan: release & coordination (outside task loop)

These are release-time actions, performed after all tasks are green and reviewed —
not part of the TDD task loop:

- **Tag & release:** create tag `v1.1.0` (must equal `plugin.json` version); the
  zip-on-tag CI publishes the single-folder asset. Verify the asset downloads 200
  and is a clean `AiConnector/` zip.
- **Directory bump:** update the AiConnector entry (version + description) in
  `kanboard-modmenu-directory/plugins.json`.
- **TimeInvoice hand-off:** the locked signatures are
  `getContextWindow(?string $profileId = null): int`,
  `countTokens(string $text, ?string $profileId = null): int`,
  `getInputBudget(?string $profileId = null, int $reserveOutputTokens = 1024): int`
  on `Kanboard\Plugin\AiConnector\Model\ModelCatalog`. TimeInvoice keeps
  AiConnector as `recommends` and guards with `class_exists`.

---

## Self-Review

**Spec coverage:**
- §3 public API (`getContextWindow`/`countTokens`/`getInputBudget`, null→default, non-throwing) → Task 2. ✔
- §4 resolution order (memo → cache → live → table → default) → Task 2 (`getContextWindow`). ✔
- §5 curated table + normalization (exact → normalized → longest-prefix family → provider default) → Task 1. ✔
- §5.1 longest-prefix-wins, provider-partitioned, ollama tag strip → Task 1 tests + resolver. ✔
- §6 caching (configModel `aiconnector_ctx_cache`, 24h TTL, model-keyed, memo) → Task 2. ✔
- §7 live Ollama single `/api/show` + `ModelDefinition::fromDiscovery` reuse, base-URL/`/v1` handling, timeout, null-on-failure → Task 3. ✔
- §8 full cited values (all six providers + Claude 5 + gpt-5.6) → Task 1 arrays. ✔
- §9 test seam (`setContextResolverForTesting`) + no-network tests → Tasks 2–3. ✔
- §10 files touched → Tasks 1–4 match the table. ✔
- §11 versioning/directory/hand-off → Task 4 + Post-plan. ✔

**Placeholder scan:** No TBD/TODO; every code step shows complete code; the one
manual step (Task 3 Step 5) is a live-E2E verification, explicitly flagged as the
un-unit-testable HTTP path, not a code placeholder. ✔

**Type consistency:** `resolve(string,string):int`, `getContextWindow(?string):int`,
`countTokens(string,?string):int`, `getInputBudget(?string,int):int`,
`resolveLive(array):?int`, `ollamaShowContext(array):?int`,
`extractOllamaContext(array):?int`, consts `CACHE_KEY`/`CACHE_TTL`/`DEFAULT_RESERVE`/
`LAST_VERIFIED`/`GLOBAL_DEFAULT` — used identically across tasks and tests. ✔
