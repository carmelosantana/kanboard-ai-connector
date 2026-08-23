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

> **`ModelContextTable::LAST_VERIFIED = '2026-08-23'`.** Each value is verified
> against a primary source (official model card, provider API docs, or the Ollama
> library page) except where flagged `UNVERIFIED (secondary)`.
>
> **Value convention:** the table stores each model's **total native context
> window** — the same quantity `ModelDefinition::fromDiscovery` reads live from
> Ollama's `model_info.<arch>.context_length` — so live and table values are
> semantically identical. Where a provider caps *input* below the advertised
> total (only OpenAI gpt-5, 272K input / 400K total), the total is stored;
> `getInputBudget()` reserves output headroom on top, and cover-note-sized prompts
> never approach either cap, so the split is immaterial in practice (documented
> caveat below).
>
> **Conservative-floor rule (Ollama families):** where a family spans multiple
> native windows by size/variant (qwen3, gemma3, phi3, deepseek), the table stores
> the **smaller** value. Under-reporting context only makes budgeting safer (a
> prompt that fits the floor fits the larger real window); over-reporting risks
> overflow. Live discovery (primary for Ollama) supplies the exact per-model value
> anyway; the table is the offline fallback.
>
> **Beta tiers excluded:** values are the standard tier available without special
> API headers. Claude Sonnet 4 / 4.5's 1M window (requires `context-1m-*` beta
> header) is **not** used — 200K standard is stored.

### 8.1 Anthropic (`provider: anthropic`)

| model id / family | context window | source |
|---|---|---|
| `claude-3-opus*` | 200000 | platform.claude.com/docs/en/about-claude/model-deprecations |
| `claude-3-5-sonnet*`, `claude-3-5-haiku*` | 200000 | ” |
| `claude-sonnet-4*`, `claude-opus-4*`, `claude-opus-4-1*` | 200000 (Sonnet 4 had a 1M **beta** — excluded) | claude.com/blog/1m-context |
| `claude-sonnet-4-5*`, `claude-opus-4-5*`, `claude-haiku-4-5*` | 200000 | platform.claude.com/docs/en/about-claude/models/overview |
| family `claude-sonnet-4-6/-4-7/-4-8`, `claude-opus-4-6/-4-7/-4-8` | 1000000 (GA default from 4.6, 2026-03-13) | platform.claude.com/docs/en/build-with-claude/context-windows |
| **provider default** | **200000** | — |

### 8.2 OpenAI (`provider: openai` **and** `openai_responses` — shared partition)

| model id / family | context window | source |
|---|---|---|
| `gpt-4o`, `gpt-4o-mini` | 128000 | developers.openai.com/api/docs/models/gpt-4o |
| `gpt-4.1`, `gpt-4.1-mini`, `gpt-4.1-nano` | 1047576 | developers.openai.com/api/docs/models/gpt-4.1 |
| `gpt-5`, `gpt-5-mini`, `gpt-5-nano`, `gpt-5-pro` | 400000 (272000 max **input**; see caveat) | developers.openai.com/api/docs/models/gpt-5 |
| `gpt-5-chat-latest` | 128000 | developers.openai.com/api/docs/models/gpt-5-chat-latest |
| `o1`, `o1-pro`, `o3`, `o3-mini`, `o3-pro`, `o4-mini` | 200000 | developers.openai.com/api/docs/models/o3 |
| `o1-mini` | 128000 | developers.openai.com/api/docs/models/o1-mini |
| family `gpt-4o`→128000, `gpt-4.1`→1047576, `gpt-5`→400000, `o1`→200000, `o3`→200000, `o4`→200000 | | |
| **provider default** | **128000** | — |

Caveat: gpt-5 family publishes 400000 **total** context but 272000 **max input**
(remainder reserved for reasoning/output). Stored value is 400000 (total);
practical prompts stay far below 272000.

### 8.3 Google Gemini (`provider: gemini`)

| model id / family | context window | source |
|---|---|---|
| `gemini-1.5-pro` | 2097152 (2M tier; 1M base) | developers.googleblog.com (Gemini API 2M) |
| `gemini-1.5-flash` | 1048576 | cloud.google.com/vertex-ai/.../gemini/1-5-flash |
| `gemini-2.0-flash`, `gemini-2.0-flash-lite` | 1048576 | ai.google.dev/gemini-api/docs/models/gemini-2.0-flash |
| `gemini-2.5-pro`, `gemini-2.5-flash`, `gemini-2.5-flash-lite` | 1048576 | ai.google.dev/gemini-api/docs/models/gemini-2.5-pro |
| family `gemini-1.5-pro`→2097152, `gemini-1.5`→1048576, `gemini-2.0`→1048576, `gemini-2.5`→1048576 | | |
| **provider default** | **1048576** | — |

(Values are documented **input** token limits; the exact integers are 1024×1024 =
1048576 and 2×1024×1024 = 2097152, not rounded millions.)

### 8.4 Mistral API (`provider: mistral`)

| model id / family | context window | source |
|---|---|---|
| `mistral-large-*` (2 / 2.1) | 128000 | docs.mistral.ai/models/model-cards/mistral-large-2-1-24-11 |
| `mistral-small-2506` (3.2) | 128000 | docs.mistral.ai/.../mistral-small-3-2-25-06 |
| `mistral-small-2409` (orig) | 32000 | UNVERIFIED (secondary) — old card removed |
| `codestral-*` (25.08 / 25.01) | 128000 | docs.mistral.ai/.../codestral-25-08 |
| `ministral-8b-*`, `ministral-3b-*` | 128000 | docs.mistral.ai/.../ministral-8b-24-1 |
| `pixtral-large-*`, `pixtral-12b-*` | 128000 | docs.mistral.ai/.../pixtral-large-24-11 |
| `mistral-medium-2508` (3.1) | 128000 | docs.mistral.ai/.../mistral-medium-3-1-25-08 |
| `mistral-medium-2604` (3.5) | 256000 | docs.mistral.ai/.../mistral-medium-3-5-26-04 |
| family `mistral-large`→128000, `mistral-small`→128000, `mistral-medium`→256000, `codestral`→128000, `ministral`→128000, `pixtral`→128000 | | |
| **provider default** | **128000** | — |

Note: `*-latest` aliases have advanced (as of Aug 2026 `mistral-small-latest`→Small 4 =
256000, `mistral-medium-latest`→Medium 3.5 = 256000, `mistral-large-latest`→Large 3).
The conservative 128000 default is safe for any unknown Mistral id.

### 8.5 xAI Grok (`provider: grok`)

| model id / family | context window | source |
|---|---|---|
| `grok-3`, `grok-3-mini`, `grok-3-fast` | 131072 | UNVERIFIED (secondary, OpenRouter; docs page retired the models) |
| `grok-4`, `grok-4-latest`, `grok-4-0709` | 256000 | x.ai/news/grok-4 |
| `grok-code-fast-1` (`grok-build-0.1`) | 256000 | docs.x.ai/developers/models |
| `grok-4-fast-reasoning`, `grok-4-fast-non-reasoning` | 2000000 | x.ai/news/grok-4-fast |
| `grok-2*`, `grok-beta` | 131072 | UNVERIFIED (secondary; retired) |
| family `grok-4-fast`→2000000, `grok-4`→256000, `grok-code`→256000, `grok-3`→131072, `grok-2`→131072 | | |
| **provider default** | **131072** | — |

(Grok 3 was announced at "1M" but the shipped API served 131072 — the real value.)

### 8.6 Ollama library (`provider: ollama`) — offline fallback only

Live `/api/show` is primary for Ollama (§7); this table is used only when the
daemon is unreachable. Values are native `context_length`; families use the
conservative floor.

| ollama family | context window | source |
|---|---|---|
| `qwen3` | 40960 (2507 variants 262144 — floor stored) | ollama.com/library/qwen3 |
| `qwen2.5`, `qwen2.5-coder` | 32768 | ollama.com/library/qwen2.5 |
| `llama3.1`, `llama3.2`, `llama3.3` | 131072 | ollama.com/library/llama3.1 |
| `gemma2` | 8192 | ollama.com/library/gemma2 |
| `gemma3` | 32768 (4b+ 131072 — floor) | ollama.com/library/gemma3 |
| `mistral` | 32768 | ollama.com/library/mistral |
| `mixtral` | 32768 (8x22b 65536 — floor) | ollama.com/library/mixtral |
| `phi3` | 4096 (`-128k` 131072 — floor) | ollama.com/library/phi3 |
| `phi4` | 16384 | huggingface.co/microsoft/phi-4 |
| `deepseek-r1` | 131072 (671b 163840 — floor) | ollama.com/library/deepseek-r1 |
| `deepseek-v3` | 131072 (native 163840 — floor) | ollama.com/library/deepseek-v3 |
| `codellama` | 16384 | ollama.com/library/codellama |
| `nomic-embed-text` | 2048 | ollama.com/library/nomic-embed-text |
| **provider default** | **8192** | — |

### 8.7 Global default

`8192` — returned only when the provider is unknown/unsupported or no
provider-default matches. Conservative and safe for the smallest local models.

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
