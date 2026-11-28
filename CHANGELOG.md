# Changelog

All notable changes to AiConnector will be documented here.

## 1.6.1 — 2026-11-28

### Security
- Hardened the provider URL guard. A host with a trailing dot (`https://api.example.com./v1`) is now refused; a base URL saved that way shows the invalid-URL error until it is re-saved without the dot.
- More IPv6 forms that carry or translate to internal IPv4 addresses are recognised and refused (SIIT, local-use NAT64, site-local).
- Provider requests no longer use an environment proxy (`https_proxy`, `all_proxy`). Callers can no longer override the address pin, the redirect limit or the proxy setting.
- The DNS test seam does nothing outside the test suite.

## 1.6.0 — 2026-11-27

### Added
- Streamed structured output: a request with a schema now streams on every HTTP provider (php-agents 0.21's `StructuredStreaming`): `Started(streaming: true)`, the document's fragments as `StructuredDelta`, `Progress` heartbeats and cancellation while it streams, then a `Finish` with the decoded `result`. The 256 KiB cap is checked before each fragment is shown; the 64-level depth rule and the `invalid_output` rules are unchanged. A provider without structured streaming keeps the blocking `structured()` call, and a structured stream rejected as a request-shape error (HTTP 400, 404, 405, 415, 422) before any output falls back to it.

### Changed
- php-agents 0.21 (from 0.19). A streamed HTTP error is now status-only: AiConnector reads the status and cancels the response, so no error body is read.

## 1.5.0 — 2026-11-27

### Added
- Streaming API for plugin authors: `AiStream::events(AiRequest)` returns one guarded, UI-agnostic stream of events (`Started`, `TextDelta`, `StructuredDelta`, `ReasoningDelta`, `ToolCallStart`, `ToolCallEnd`, `Progress`, then exactly one of `Finish`, `Refused` or `Error`). It has no transport: the host decides how to show it. Every outcome is an event, and no php-agents type appears in any signature.
- Guard rails run in order before any request goes out: readiness, signed-in user, a registered feature, model routing, provider setup, then one atomic reservation. Each attempt is closed as finished or failed, however the stream ends.
- `AiQuota::reserve()`: checks an attempt key, a per-person concurrent limit and the hourly quota in one step, and counts the request. A repeated attempt key is refused as a duplicate and never charged twice. `consume()` is unchanged.
- A third AI limit, **AI requests running at once per person** (default 3, ceiling 20), in **Settings → AI Connector → AI Limits**.
- Cancellation through the `AiCancellation` interface, with idle and total time limits per request.
- A new `aiconnector_attempts` table (kept 24 hours).
- Structured requests (`AiRequest` with a schema) answer through one blocking `structured()` call for now, with the provider's response held to 1 MiB and the emitted document to 256 KiB and 64 levels. A streamed structured answer is planned with php-agents 0.21.
- A request's total time limit counts from its reservation, including time the host spends between events; no provider call starts after it.
- php-agents 0.19 (from 0.17), which needs the PHP mbstring extension.
- The attempts table is indexed by creation time (schema v4), for the cleanup each reservation runs.

### Changed
- A failed reservation now logs a distinct reason with the exception's class name (never its message).

## 1.4.0 — 2026-11-27

### Added
- Model routing: AI Connector decides which profile each AI request uses. The most specific choice wins: a person's pick (where a plugin offers the picker), the project's profile, the feature's assignment, the plugin's assignment, then the global default. A deleted or not-ready profile is skipped and logged, and resolution falls to the next level. A project profile an admin no longer makes available stops applying, and a pick counts only for a registered feature.
- **Settings → AI Connector → Model routing** (admins): mark which profiles are **Available to users**, assign a profile per plugin and per AI feature, and **Lock** a feature so it always uses its assignment and shows no picker. Broken assignments are flagged.
- A project **AI** page (project managers): one **AI profile for this project**, chosen from the available profiles, and **Let members switch the AI model**.
- Routing API for plugin authors: the `aiconnector:features` hook (`{id, label, plugin}`), `ProviderRegistry::resolve($feature, $projectId, $pick)`, `pickerOptions()`, `offeredProfiles()`, `profileReady()`, `features()`, `brokenAssignments()`, and the shared `AiConnector:picker/select` partial. A pick is checked on the server: one the person may not make is ignored.

## 1.3.0 — 2026-11-25

### Added
- AI limits, shared by every plugin that uses AI through AI Connector: a per-person hourly request quota (default 30) and a longest-request cap in characters (default 32,000). Admins set both on **Settings → AI Connector → AI Limits**, up to 10,000 requests an hour and 1,000,000 characters. Neither can be switched off; a stored value that is not a whole number in that range reads as the default.
- `AiQuota`, the PHP API behind the limits: consuming plugins call `consume($userId, $feature, $input)` before each provider request and show the refusal it returns. Requests it lets through are kept for an hour in a new `aiconnector_requests` table, and each check-and-count runs under a per-user row lock (`aiconnector_quota_users`), so a person's parallel requests cannot overshoot the quota. It refuses unknown users and any request it could not count, and throws if called inside a database transaction.
- Test Connection counts against the admin's own hourly limit.

## 1.2.1 - 2026-11-25

### Changed
- The settings controller is now `AiConnectorSettingsController`. Kanboard keys access rules by short controller name, so another plugin's rule for a `SettingsController` would have governed AI Connector's settings too. Links that name `SettingsController` with `plugin=AiConnector` must use the new name.
- The renamed controller registers its own admin-only access rule; every settings action stays admins-only at the access-map level, not only inside each action.

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
