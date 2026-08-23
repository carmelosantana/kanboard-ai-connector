# AiConnector — Model Context-Window Discovery & Token Counting API

**Status:** Approved design (2026-08-23)
**Target release:** AiConnector v1.1.0 (additive)
**Consumer:** TimeInvoice (AI invoice cover-note feature) and future suite plugins

## 1. Purpose

Give sibling Kanboard plugins a public PHP API to (a) discover a configured
model's maximum **context window** and (b) **estimate token counts**, so a
consumer can fit a prompt to whatever model/profile is selected — especially
small local Ollama models where the operator may run arbitrary quants with a
custom `num_ctx`.

AiConnector is the right owner: it already holds provider/model configuration
(`ProviderRegistry`) and vendors `carmelosantana/php-agents`, which already
provides the primitives (`Context/ContextWindow`, `Context/HeuristicCounter`,
`Context/TokenCounterFactory`, `Config/ModelDefinition::fromDiscovery()`). This
capability is a thin, well-tested Kanboard-facing wrapper plus a curated
fallback table — **not** a reimplementation of any of those.

## 2. Non-goals (YAGNI)

- No exact BPE tokenization. `yethee/tiktoken-php` is **not** a dependency (it
  needs a runtime vocab download + writable cache and helps only OpenAI-family
  models, not the motivating local models). Counting is heuristic-only for v1.
- No live discovery for cloud providers (Anthropic/OpenAI/Gemini/Mistral/Grok).
  Those resolve from the curated table; only Ollama gets a live call.
- No public cache-management or force-refresh method in v1 (TTL + model-keyed
  cache handle staleness). Revisit if a consumer needs it.
- No UI. This is a PHP API. (A Settings surface can be added later.)

## 3. Public API (the locked contract for TimeInvoice)

New class `Kanboard\Plugin\AiConnector\Model\ModelCatalog`, constructed exactly
like `ProviderRegistry`:

```php
use Kanboard\Plugin\AiConnector\Model\ModelCatalog;

$catalog = new ModelCatalog($this->container);
```

### 3.1 Methods

```php
/**
 * Maximum context length in tokens for the profile's model.
 * @param ?string $profileId  null -> the default profile.
 * Never throws; unknown/missing profile falls through to the conservative default.
 */
public function getContextWindow(?string $profileId = null): int;

/**
 * Heuristic token-count estimate for $text, using a provider-appropriate
 * chars-per-token ratio (openai 4.0, anthropic 3.5, others 4.0).
 * @param ?string $profileId  null -> the default profile.
 * Never throws.
 */
public function countTokens(string $text, ?string $profileId = null): int;

/**
 * Prompt-token budget: how many input tokens fit once output headroom is
 * reserved. Equivalent to max(0, getContextWindow($profileId) - $reserveOutputTokens).
 * @param int $reserveOutputTokens  headroom for the model's reply. Default 1024.
 *        Internally clamped so it never exceeds half the context window
 *        (guards tiny-context local models).
 * Never throws.
 */
public function getInputBudget(?string $profileId = null, int $reserveOutputTokens = 1024): int;
```

**Contract guarantees:**
- All three accept `null` → default profile, mirroring `ProviderRegistry`.
- **None throw.** A consumer always gets a usable integer. Unknown profile,
  unconfigured plugin, unreachable Ollama, unknown model id → conservative
  default (see §5). This is deliberate: TimeInvoice sizing a prompt must not
  have to try/catch.
- Secrets discipline identical to `ProviderRegistry`: no API key is ever logged
  or placed in a message/exception. `ModelCatalog` never handles keys directly —
  Ollama (the only live path) is keyless.

### 3.2 Example (TimeInvoice usage)

```php
$catalog = new ModelCatalog($this->container);
$budget  = $catalog->getInputBudget($profileId);          // e.g. 7168 for an 8k model
$draft   = $this->buildPrompt(...);
while ($catalog->countTokens($draft, $profileId) > $budget) {
    $draft = $this->trim($draft);                          // shrink until it fits
}
```

## 4. `getContextWindow()` resolution order

```
getContextWindow(id):
  1. in-request memo[id]                  -> return          (no I/O)
  2. persisted cache[id|model] if fresh   -> return          (§6)
  3. provider == ollama:
        live POST {baseUrl}/api/show      -> parse -> cache -> return   (§7)
  4. curated table lookup:                                    (§5, §8)
        exact model id
        -> normalized model id
        -> family prefix
        -> provider default
  5. conservative global default (8192)   -> return
```

`countTokens()` and `getInputBudget()` do **no** network I/O beyond what
`getContextWindow()` triggers (budget calls it; counting is pure).

## 5. Curated fallback table

Lives in its own data file `Model/ModelContextTable.php` for maintainability,
exposing:
- a `LAST_VERIFIED` date constant,
- an exact-id map (`model id` → tokens),
- a family-prefix map (`family` → tokens),
- a per-provider default map (used when nothing matches),
- and the global default `8192`.

Populated from the deep-research pass in §8 (cited). Resolution prefers the most
specific match: exact id → normalized id → family prefix → provider default →
global default.

### 5.1 Model-id normalization

Concrete ids are reduced before family matching:
- lowercase;
- strip Ollama tag/quant suffixes after `:` (e.g. `llama3.1:8b-instruct-q4_K_M`
  → `llama3.1`);
- strip trailing size/variant tokens (`-instruct`, `-chat`, `-latest`, date
  stamps like `-20250514`) when matching a family;
- provider-scoped: normalization + lookup are done within the profile's provider
  where the table is provider-partitioned, so an OpenAI `mistral-*` alias can't
  collide with the Ollama `mistral` family.

## 6. Caching

- **In-request memo:** private array on the `ModelCatalog` instance, keyed by
  profile id. Zero-cost repeat calls within one request.
- **Persisted cache:** a single `configModel` key `aiconnector_ctx_cache`
  holding JSON: `{ "<profileId>|<model>": { "context_window": int,
  "source": "live"|"table"|"default", "verified_at": int(unix) } }`.
  - **TTL:** 24h. A stale entry is ignored and re-resolved.
  - **Model-keyed:** the cache key includes the profile's current model id, so
    editing a profile's model auto-misses (no explicit invalidation needed).
  - Only **live** (Ollama) results are worth persisting; table/default results
    are cheap to recompute but may also be cached to short-circuit step 4.
    (Implementation may cache all three; TTL bounds staleness either way.)
- No public cache API in v1.

## 7. Live Ollama discovery (the only network path)

Rationale for a targeted call over reusing `OllamaProvider::models()`:
`models()` performs `GET /api/tags` then a `POST /api/show` **per installed
model** (N+1) to build the full catalog — slow on a box with many models. For a
single profile we need exactly one model's context length.

Design:
- A small injectable resolver: `ContextResolverInterface` (or a `callable`)
  with the shape `fn(array $profile): ?int`.
- **Production resolver** does one `POST {baseUrl}/api/show` with
  `{"name": "<model>"}` and parses the response through the vendored
  `ModelDefinition::fromDiscovery('ollama', [...])` — reusing the existing,
  tested normalization (`model_info["<arch>.context_length"]`, etc.) rather than
  reinventing parsing. Only the single HTTP call is plugin-local.
  - Base URL resolution reuses the same logic as
    `ProviderRegistry::resolveOllamaBaseUrl()` (profile override → `OLLAMA_HOST`
    → default `http://localhost:11434`). `/api/show` is a native endpoint, so
    strip any `/v1` suffix (matching `OllamaProvider`).
  - Short timeout (~5s). Any failure → `null`, and resolution falls through to
    the curated table (§4 step 4). Never throws to the caller.
- **HTTP client:** Symfony HttpClient (already vendored via php-agents) or
  Kanboard's client; the resolver isolates this choice behind the interface.

## 8. Deep-research: curated context-window data

> **NOTE:** This section is populated by the `/deep-research` pass. Values below
> carry a source URL; each is verified against a primary source (official model
> card, provider API docs, or the Ollama library page) where possible, and
> flagged `UNVERIFIED (secondary)` otherwise. `ModelContextTable::LAST_VERIFIED`
> records the compilation date.

_(Tables + citations inserted here from the research pass before the spec is
finalized. Covers: Ollama library (qwen3/2.5, llama3.1/3.2/3.3, gemma2/3,
mistral/mixtral, phi, deepseek, codellama, embeds), Anthropic Claude, OpenAI
gpt-4o/4.1/gpt-5/o-series, Google Gemini 1.5/2.0/2.5, Mistral API, xAI Grok, plus
normalization notes and per-provider default fallbacks.)_

## 9. Test seams (unit tests need no network)

Mirrors `ProviderRegistry::setProviderForTesting()`:

```php
/** Stub live discovery so tests exercise resolution/caching without a network call. */
public function setContextResolverForTesting(callable $fn): void; // fn(array $profile): ?int
```

- **Live path:** inject a resolver returning a fixed int (or `null` to simulate
  an unreachable Ollama) and assert cache write / fallthrough.
- **Counting:** pure — assert heuristic ratios per provider directly.
- **Table:** assert exact/normalized/family/default resolution with fixture
  profiles.
- **No throw:** assert every method returns the default for an unconfigured
  container / unknown profile.

Follows the suite's TDD flow: failing test → minimal impl → green, small commits.

## 10. Files touched

| File | Change |
|---|---|
| `Model/ModelCatalog.php` | **new** — public API + resolution/cache/memo + test seam |
| `Model/ModelContextTable.php` | **new** — curated map + `LAST_VERIFIED` |
| `Test/ModelCatalogTest.php` | **new** — unit tests (resolution, cache, counting, no-throw) |
| `plugin.json` | version `1.0.0` → `1.1.0`; description touch (mention context discovery) |
| `Plugin.php` | `getPluginVersion()` → `1.1.0`; description string aligned |
| `README.md` | document the new `ModelCatalog` API |
| `CHANGELOG.md` | v1.1.0 entry |

`ProviderRegistry.php` is **not** modified.

## 11. Release & coordination

- **Versioning:** `plugin.json`, `Plugin.php::getPluginVersion()`, and the git
  tag `v1.1.0` must all agree (suite CI fails otherwise). Buildless: the
  committed tree (incl. `vendor/`) is what ships; no new dependency is added.
- **Directory:** because `plugin.json` version (and description) change, bump the
  AiConnector entry in `kanboard-modmenu-directory/plugins.json`.
- **TimeInvoice hand-off:** the §3 signatures are the locked contract. TimeInvoice
  can code against them before AiConnector's full release. AiConnector remains a
  `recommends` (never `requires`) dependency; TimeInvoice must degrade if
  `ModelCatalog` is absent (class_exists guard).

## 12. Open questions

None outstanding. (Defaults confirmed: reserve 1024, cache TTL 24h, global
default 8192, targeted single `/api/show` reuse of `ModelDefinition::fromDiscovery`.)
