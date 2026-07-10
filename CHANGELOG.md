# Changelog

All notable changes to AiConnector will be documented here.

## [1.0.0] — 2026-07-10

### Added
- **Provider profiles**: named `{id,label,provider,model,base_url}` profiles with one global default; add/edit/remove in Settings → AI Connector.
- **Seven provider types** (all HTTP, via php-agents): Anthropic, OpenAI (Chat Completions), OpenAI Responses (Codex/gpt-5), Grok (xAI), Gemini, Mistral, and Ollama (keyless).
- **`ProviderRegistry` PHP API** for other plugins: `listProfiles()`, `getDefaultProfileId()`, `isReady()`, `buildProvider()`, and a provider-agnostic `structured()` that normalizes both php-agents return shapes to a decoded PHP array.
- **Secret handling**: API keys stored separately from the profiles JSON (`aiconnector_key_<id>`), masked in the UI, never logged/echoed, with per-provider env-var fallback (`ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / `XAI_API_KEY` / `GEMINI_API_KEY` / `MISTRAL_API_KEY`; Ollama keyless, honours `OLLAMA_HOST`).
- **Per-profile Test Connection** (admin, reusable-CSRF, CSP-safe external JS).
- **Bundled php-agents** in `vendor/`; loaded only at request time (load-order-safe).
