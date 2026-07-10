# AiConnector — Kanboard Plugin

Universal multi-provider AI backend for the Kanboard plugin suite. AiConnector
owns provider configuration, API-key storage, and the [`php-agents`](https://github.com/carmelosantana/php-agents)
library, and exposes a small PHP API (`ProviderRegistry`) that other plugins
consume instead of talking to any AI provider directly. It has no user-facing
feature of its own beyond its Settings page — it exists to be depended on.

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4
- An API key for at least one hosted provider, or a reachable Ollama server
  (no key required)

## Installation

1. Copy or clone this directory to `<kanboard-root>/plugins/AiConnector/`.
2. Inside the plugin directory, install PHP dependencies (this vendors
   `php-agents` and its HTTP client — there is no way to skip this step):
   ```
   composer install --no-dev
   ```
3. Set file permissions so the web server can read the plugin:
   ```
   chmod -R o+rX AiConnector/
   ```
4. Go to **Settings → Plugins** in Kanboard to confirm the plugin is active.
5. Go to **Settings → AI Connector** to add a provider profile.

## Provider types

AiConnector supports seven provider types, all over HTTP via `php-agents`:

| Provider type | Label | Default model | Env var fallback |
|---|---|---|---|
| `anthropic` | Anthropic (Claude) | `claude-sonnet-4-20250514` | `ANTHROPIC_API_KEY` |
| `openai` | OpenAI (Chat Completions) | `gpt-4o` | `OPENAI_API_KEY` |
| `openai_responses` | OpenAI Responses (Codex / gpt-5) | `gpt-5` | `OPENAI_API_KEY` |
| `grok` | Grok (xAI) | `grok-3` | `XAI_API_KEY` |
| `gemini` | Google Gemini | `gemini-2.5-flash` | `GEMINI_API_KEY` |
| `mistral` | Mistral | `mistral-large-latest` | `MISTRAL_API_KEY` |
| `ollama` | Ollama (local, keyless) | `llama3.2` | — (honours `OLLAMA_HOST`) |

Both OpenAI-flavored types share the `OPENAI_API_KEY` env var fallback since
they're the same account/key on OpenAI's side — only the API surface
(Chat Completions vs. Responses) differs.

## Profiles + default

Configuration is organized as named **profiles**: `{id, label, provider,
model, base_url}`. Add, edit, and remove profiles from **Settings → AI
Connector**. Exactly one profile can be marked the **default** — it's the one
`ProviderRegistry::buildProvider()` / `structured()` use when a consumer
doesn't pass an explicit profile id.

Any plugin with two or more profiles configured can offer a point-of-use
provider picker instead of always using the default; see SubtaskGenerator's
Generate-subtasks modal for an example.

## Key storage

- API keys are stored **separately** from the profiles list, under
  `aiconnector_key_<profile-id>` in Kanboard's `settings` table — never
  embedded in the profiles JSON itself.
- The key field in the settings form always renders masked (`••••••••`) and
  is never echoed back into the HTML response. Submitting the form with the
  placeholder unchanged, or leaving the field blank, preserves the existing
  stored key.
- Keys are never written to the PHP error log. On a provider failure, only
  the exception class and code are logged — never the exception message,
  request URL, or provider response body.
- As a fallback, when a profile's stored key is empty, AiConnector resolves
  the provider type's env var (see the table above). Ollama is keyless and
  ignores both — it instead honours `OLLAMA_HOST` for the server address (see
  below).
- Ollama base URL resolution order: profile `base_url` override →
  `OLLAMA_HOST` (with `/v1` appended) → `http://localhost:11434/v1`.

## Test Connection

Each profile row on the Settings page has a **Test Connection** button
(admin-only) that fires a live request through the configured provider and
reports success/failure inline, without leaving the page. It's wired via the
external, CSP-safe `Assets/js/ai-connector.js` — no inline `<script>` blocks.

## The `ProviderRegistry` API (for plugin authors)

Other plugins consume AiConnector by instantiating `ProviderRegistry`
directly — there's no service-container alias to look up:

```php
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

$registry = new ProviderRegistry($this->container);
```

Message shape used throughout — a plain array of role/content pairs, no
php-agents types involved:

```php
$messages = [
    ['role' => 'system',    'content' => 'You are a helpful assistant.'],
    ['role' => 'user',      'content' => 'Summarize this task.'],
    ['role' => 'assistant', 'content' => '...'], // optional, prior turns
];
```

Public methods:

- `listProfiles(): array` — `{id, label, provider, model}` per profile (no
  key, no base_url) for populating a dropdown.
- `getDefaultProfileId(): string` — the configured default's id, or `''`
  when none is set or it's dangling.
- `isReady(): bool` — true when at least one profile has a resolvable key
  (stored or env) or is keyless (Ollama). No network call — safe to use for
  gating whether to show an "AI" feature at all.
- `buildProvider(?string $profileId = null): ProviderInterface` — builds a
  configured `php-agents` provider instance for the given profile id (or the
  default). Throws `\RuntimeException` on a missing/unknown profile or
  unsupported provider type; the message never contains a key.
- `structured(array $messages, string $schema, ?string $profileId = null): array`
  — the one call most consumers need. Maps `$messages` to `php-agents`
  message objects, invokes the provider's `structured()`, and normalizes
  **both** of `php-agents`' return shapes (a decoded array for
  Anthropic/OpenAI-Responses tool-use, or a `Response` object with a JSON
  `->content` string for OpenAI/Grok/Gemini/Mistral/Ollama) to a single
  decoded PHP array. Consumers never see or import a
  `CarmeloSantana\PHPAgents\*` class.

### Minimal example

```php
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

$registry = new ProviderRegistry($this->container);

if (! $registry->isReady()) {
    // no profile configured / no key resolvable — hide the AI feature
    return;
}

$result = $registry->structured(
    messages: [
        ['role' => 'user', 'content' => 'Return 3 short todo titles for: ship the release'],
    ],
    schema: $mySchemaJson, // provider-specific JSON schema string
    // profileId: null uses the default; pass an explicit id for a point-of-use picker
);

// $result is a decoded PHP array — use it directly.
```

## php-agents load-order rule

AiConnector is the **only** plugin in this suite that bundles `php-agents`
(vendored under `vendor/`). Kanboard gives no guarantee about plugin
`initialize()` call order, so:

- AiConnector's own `initialize()` loads `vendor/autoload.php` but never
  references a `CarmeloSantana\PHPAgents\*` class at init time.
- Consumer plugins (e.g. SubtaskGenerator) must **never** reference a
  `CarmeloSantana\PHPAgents\*` class, and should avoid triggering autoload of
  one, during their own `initialize()`. Only call into `ProviderRegistry`
  methods that touch `php-agents` (`buildProvider()` / `structured()`) at
  request-handling time — inside a controller action, not plugin bootstrap.
  `listProfiles()`, `getDefaultProfileId()`, and `isReady()` are safe at any
  time since they touch no `php-agents` class.

## License

MIT — see [LICENSE](LICENSE).
