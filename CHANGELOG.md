# Changelog

All notable changes to AiConnector will be documented here.

## 1.2.0 - 2026-11-19

### Changed
- Bundled `php-agents` bumped to 0.17.0: `RunLimits` and `$providerOptions` on
  `AbstractAgent`, image resolvers, and new finish reasons (`refusal`,
  `content_filter`, `context_window_exceeded`, `pause_turn`).
  Plugins that use AiConnector's bundled `php-agents` get these classes.
- The default Anthropic model (prefill for new profiles and fallback for profiles
  with no model) is now `claude-sonnet-5-5`, replacing `claude-sonnet-4-20250514`.
  Profiles with a model already set are unchanged.

## 1.1.6 - 2026-11-18

### Security
- SSRF hardening for provider base URLs (OpenAI-compatible, OpenAI Responses, Ollama).
  The URL is checked when a profile is saved and before every request, after DNS
  resolution: private, loopback and CGNAT addresses (incl. IPv6 `::1`, `fc00::/7`
  and IPv4-mapped forms) need the new **Allow providers on private networks** opt-in;
  link-local and cloud-metadata addresses (`169.254.0.0/16`, `fe80::/10`,
  `fd00:ec2::254`, `100.100.100.200`), multicast and reserved ranges are always
  refused; numeric shorthand hosts (`2130706433`, `0x7f.1`) are refused.
- New admin **Allowed hosts** list (exact hosts / IPs or `*.domain`); empty allows any
  public host.
- Requests go through an HTTP client pinned to the checked address with redirects
  off, so DNS re-binding or a 30x cannot move them. Applies to the Ollama
  `/api/show` context lookup too.

### Upgrade note
- A local Ollama (the default `http://localhost:11434`, `OLLAMA_HOST`, or a LAN
  address) stops working until an admin ticks **Allow providers on private
  networks** in Settings → AI Connector → Network Security.

## 1.1.5 — 2026-11-17

### Fixed
- Stored provider keys are bound to their endpoint, base URLs are validated, and Test Connection returns a generic error.

## [1.1.4] — 2026-11-17

### Security
- Bumped `symfony/http-client` 8.1.9 → 8.1.11 (and the `*-contracts` packages to 3.8.1) for
  CVE-2026-121971 (ScopingHttpClient leaked default-scope credentials to other origins)
  and CVE-2026-123119 (NoPrivateNetworkHttpClient DNS-rebinding bypass).
- Pinned `actions/checkout` in the release workflow to a commit SHA.

### Fixed
- Settings: **Remove** and **Make default** did nothing — the handlers read `profile_id`
  from the query string while the forms POST it. They now read the form body.

## [1.1.3] — 2026-10-22

### Changed
- Re-vendored `carmelosantana/php-agents` v0.15.2 → v0.16.1, which keeps a Gemini
  `structured()` schema's `properties` map an object (Kanboard #4556) and adds the
  MCP client. Symfony HTTP client 8.1.1 → 8.1.9, contracts 3.7.1 → 3.7.3.
  `composer audit`: no advisories.

## [1.1.2] — 2026-09-04

### Fixed
- Re-vendored `carmelosantana/php-agents` (v0.15.2) to pick up the `structured()`
  OpenAI strict-mode schema fix. v0.15.1 wrapped the schema with `strict: true`
  but forwarded it verbatim; real OpenAI (ChatGPT) rejects any object schema
  lacking `additionalProperties: false` (or not requiring every property) with an
  HTTP 400 — so `ProviderRegistry::structured()` threw and consumers saw failures
  like TimeReport's *"The summary could not be generated."* (v0.15.1 only ever ran
  against LAN Ollama, which does not enforce strict mode). The library now
  normalizes the schema for strict mode, and disables strict for schemas that
  intentionally use open maps. Verified against php-agents' suite and live Coqui
  (2692 tests, no regressions).

## [1.1.1] — 2026-08-30

### Fixed
- Re-vendored `carmelosantana/php-agents` to pick up the `structured()`
  `response_format.json_schema` wrapper fix (`{name, schema, strict}` per the
  OpenAI / Ollama OpenAI-compat spec). Without the wrapper, servers ignored the
  schema and returned free-form prose, so `ProviderRegistry::structured()`
  normalized to an empty array — the root cause of downstream structured-output
  failures (e.g. TimeInvoice's AI cover-note 502). Verified live against LAN
  Ollama (non-thinking + thinking models) end-to-end.

## [1.1.0] — 2026-08-23

### Added
- `ModelCatalog` public API: `getContextWindow()`, `countTokens()`,
  `getInputBudget()` for model context-window discovery and token estimation.
- Live Ollama context discovery via `/api/show` with a 24h cache; curated
  fallback table (`ModelContextTable`) covering Ollama, Anthropic, OpenAI,
  Gemini, Mistral, and xAI Grok, plus a conservative 8192 default.
- `setContextResolverForTesting()` seam for network-free unit tests.

## [1.0.0] — 2026-07-10

### Added
- **Provider profiles**: named `{id,label,provider,model,base_url}` profiles with one global default; add/edit/remove in Settings → AI Connector.
- **Seven provider types** (all HTTP, via php-agents): Anthropic, OpenAI (Chat Completions), OpenAI Responses (Codex/gpt-5), Grok (xAI), Gemini, Mistral, and Ollama (keyless).
- **`ProviderRegistry` PHP API** for other plugins: `listProfiles()`, `getDefaultProfileId()`, `isReady()`, `buildProvider()`, and a provider-agnostic `structured()` that normalizes both php-agents return shapes to a decoded PHP array.
- **Secret handling**: API keys stored separately from the profiles JSON (`aiconnector_key_<id>`), masked in the UI, never logged/echoed, with per-provider env-var fallback (`ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / `XAI_API_KEY` / `GEMINI_API_KEY` / `MISTRAL_API_KEY`; Ollama keyless, honours `OLLAMA_HOST`).
- **Per-profile Test Connection** (admin, reusable-CSRF, CSP-safe external JS).
- **Bundled php-agents** in `vendor/`; loaded only at request time (load-order-safe).
