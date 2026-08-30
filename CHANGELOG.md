# Changelog

All notable changes to AiConnector will be documented here.

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
