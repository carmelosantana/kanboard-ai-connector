<?php

namespace Kanboard\Plugin\AiConnector\Model;

use Kanboard\Core\Base;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;

/**
 * ProviderRegistry — the public PHP API other plugins consume for AI provider access.
 *
 * Consumers instantiate directly: new ProviderRegistry($this->container). Reads
 * named provider profiles + separately-stored API keys from configModel and
 * builds/uses php-agents providers.
 *
 * php-agents timing: every CarmeloSantana\PHPAgents\* reference lives inside a
 * method body reached only at request time (buildProvider/structured). Merely
 * loading this class (e.g. class_exists from a consumer's initialize()) does NOT
 * require the php-agents autoloader — PHP resolves method type-hints lazily.
 * isReady()/listProfiles()/getDefaultProfileId() touch no php-agents class.
 *
 * Secrets: resolveKey() NEVER logs; keys never enter the profiles JSON, an
 * exception message, or any response.
 *
 * @package Kanboard\Plugin\AiConnector\Model
 * @author  Carmelo Santana
 */
class ProviderRegistry extends Base
{
    // ── Provider types ────────────────────────────────────────────────────────
    public const PROVIDER_ANTHROPIC        = 'anthropic';
    public const PROVIDER_OPENAI           = 'openai';
    public const PROVIDER_OPENAI_RESPONSES = 'openai_responses';
    public const PROVIDER_GROK             = 'grok';
    public const PROVIDER_GEMINI           = 'gemini';
    public const PROVIDER_MISTRAL          = 'mistral';
    public const PROVIDER_OLLAMA           = 'ollama';

    /** Human labels, keyed by provider type. Also defines the supported set. */
    public const PROVIDERS = [
        self::PROVIDER_ANTHROPIC        => 'Anthropic (Claude)',
        self::PROVIDER_OPENAI           => 'OpenAI (Chat Completions)',
        self::PROVIDER_OPENAI_RESPONSES => 'OpenAI Responses (Codex / gpt-5)',
        self::PROVIDER_GROK             => 'Grok (xAI)',
        self::PROVIDER_GEMINI           => 'Google Gemini',
        self::PROVIDER_MISTRAL          => 'Mistral',
        self::PROVIDER_OLLAMA           => 'Ollama (local, keyless)',
    ];

    /** Default model id per provider type (placeholder/prefill only). */
    public const DEFAULT_MODELS = [
        self::PROVIDER_ANTHROPIC        => 'claude-sonnet-4-20250514',
        self::PROVIDER_OPENAI           => 'gpt-4o',
        self::PROVIDER_OPENAI_RESPONSES => 'gpt-5',
        self::PROVIDER_GROK             => 'grok-3',
        self::PROVIDER_GEMINI           => 'gemini-2.5-flash',
        self::PROVIDER_MISTRAL          => 'mistral-large-latest',
        self::PROVIDER_OLLAMA           => 'llama3.2',
    ];

    /** Env-var fallback per provider type. Ollama is keyless (absent). */
    public const ENV_VARS = [
        self::PROVIDER_ANTHROPIC        => 'ANTHROPIC_API_KEY',
        self::PROVIDER_OPENAI           => 'OPENAI_API_KEY',
        self::PROVIDER_OPENAI_RESPONSES => 'OPENAI_API_KEY',
        self::PROVIDER_GROK             => 'XAI_API_KEY',
        self::PROVIDER_GEMINI           => 'GEMINI_API_KEY',
        self::PROVIDER_MISTRAL          => 'MISTRAL_API_KEY',
    ];

    /** Provider types that need no API key. */
    public const KEYLESS = [self::PROVIDER_OLLAMA];

    /** Default base URL per provider type (empty = use php-agents class default). */
    public const DEFAULT_BASE_URLS = [
        self::PROVIDER_OPENAI => 'https://api.openai.com/v1',
        self::PROVIDER_OLLAMA => 'http://localhost:11434/v1',
    ];

    /** Config keys + masking sentinel. */
    public const PROFILES_KEY   = 'aiconnector_profiles';
    public const DEFAULT_KEY    = 'aiconnector_default';
    public const KEY_PREFIX     = 'aiconnector_key_';
    public const KEY_PLACEHOLDER = '••••••••';

    // ── Profile reads ─────────────────────────────────────────────────────────

    /**
     * Full profile structs incl. base_url. Order = storage order.
     *
     * @return array<int, array{id:string,label:string,provider:string,model:string,base_url:string}>
     */
    public function getProfiles(): array
    {
        $raw = $this->configModel->get(self::PROFILES_KEY, '');
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $p) {
            if (! is_array($p) || ! isset($p['id'], $p['provider'])) {
                continue;
            }
            $out[] = [
                'id'       => (string) $p['id'],
                'label'    => (string) ($p['label'] ?? $p['id']),
                'provider' => (string) $p['provider'],
                'model'    => (string) ($p['model'] ?? ''),
                'base_url' => (string) ($p['base_url'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Profiles for dropdowns — id/label/provider/model only (NO key, NO base_url).
     *
     * @return array<int, array{id:string,label:string,provider:string,model:string}>
     */
    public function listProfiles(): array
    {
        $out = [];
        foreach ($this->getProfiles() as $p) {
            $out[] = [
                'id'       => $p['id'],
                'label'    => $p['label'],
                'provider' => $p['provider'],
                'model'    => $p['model'],
            ];
        }
        return $out;
    }

    /** The default profile id, or '' when none / dangling. */
    public function getDefaultProfileId(): string
    {
        $id = (string) $this->configModel->get(self::DEFAULT_KEY, '');
        if ($id === '') {
            return '';
        }
        return $this->findProfile($id) !== null ? $id : '';
    }

    /** One full profile struct, or null. */
    public function findProfile(string $id): ?array
    {
        foreach ($this->getProfiles() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /** Whether aiconnector_key_<id> is non-empty. */
    public function hasStoredKey(string $id): bool
    {
        return (string) $this->configModel->get(self::KEY_PREFIX . $id, '') !== '';
    }

    /**
     * True when ≥1 profile has a resolvable key (stored/env) or is keyless (ollama).
     * No network call.
     */
    public function isReady(): bool
    {
        foreach ($this->getProfiles() as $p) {
            if (in_array($p['provider'], self::KEYLESS, true)) {
                return true;
            }
            $stored = (string) $this->configModel->get(self::KEY_PREFIX . $p['id'], '');
            if ($this->resolveKey($p['provider'], $stored) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve an API key: stored (if non-empty) → env var for the provider type → ''.
     * NEVER logs the returned value.
     */
    public function resolveKey(string $providerType, string $storedKey): string
    {
        if ($storedKey !== '') {
            return $storedKey;
        }
        $envVar = self::ENV_VARS[$providerType] ?? null;
        if ($envVar !== null) {
            $val = getenv($envVar);
            if ($val !== false && $val !== '') {
                return $val;
            }
        }
        return '';
    }

    // ── Provider building + structured calls ────────────────────────────────────

    /** Test seam — when set, buildProvider()/structured() use this instead of config. */
    private ?ProviderInterface $injectedProvider = null;

    /** Inject a provider for tests so no network call is made. */
    public function setProviderForTesting(ProviderInterface $provider): void
    {
        $this->injectedProvider = $provider;
    }

    /**
     * Build a configured php-agents provider for $profileId (null → the default).
     *
     * @throws \RuntimeException on missing/unknown profile or unsupported provider
     *         type. The message NEVER contains an API key.
     */
    public function buildProvider(?string $profileId = null): ProviderInterface
    {
        if ($this->injectedProvider !== null) {
            return $this->injectedProvider;
        }

        $id = $profileId ?? $this->getDefaultProfileId();
        if ($id === '') {
            throw new \RuntimeException('[AiConnector] No AI provider profile is configured. Add one in Settings → AI Connector.');
        }

        $profile = $this->findProfile($id);
        if ($profile === null) {
            throw new \RuntimeException(sprintf('[AiConnector] Unknown provider profile "%s".', $id));
        }

        $type    = $profile['provider'];
        $model   = $profile['model'] !== '' ? $profile['model'] : (self::DEFAULT_MODELS[$type] ?? '');
        $baseUrl = $profile['base_url'];
        $stored  = (string) $this->configModel->get(self::KEY_PREFIX . $id, '');
        $key     = $this->resolveKey($type, $stored);

        return match ($type) {
            self::PROVIDER_ANTHROPIC => new AnthropicProvider(model: $model, apiKey: $key),
            self::PROVIDER_OPENAI => new OpenAICompatibleProvider(
                model: $model,
                baseUrl: $baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URLS[self::PROVIDER_OPENAI],
                apiKey: $key,
            ),
            self::PROVIDER_OPENAI_RESPONSES => $baseUrl !== ''
                ? new OpenAIResponsesProvider(model: $model, baseUrl: $baseUrl, apiKey: $key)
                : new OpenAIResponsesProvider(model: $model, apiKey: $key),
            self::PROVIDER_GROK    => new XAIProvider(model: $model, apiKey: $key),
            self::PROVIDER_GEMINI  => new GeminiProvider(model: $model, apiKey: $key),
            self::PROVIDER_MISTRAL => new MistralProvider(model: $model, apiKey: $key),
            self::PROVIDER_OLLAMA  => new OllamaProvider(
                model: $model,
                baseUrl: $this->resolveOllamaBaseUrl($baseUrl),
            ),
            default => throw new \RuntimeException(sprintf(
                '[AiConnector] Unsupported provider type "%s". Supported: %s',
                $type,
                implode(', ', array_keys(self::PROVIDERS))
            )),
        };
    }

    /**
     * Provider-agnostic structured call. Maps $messages to php-agents messages,
     * calls the provider's structured(), and normalizes BOTH return shapes
     * (decoded array | Response with JSON ->content) to a decoded PHP array.
     *
     * @param array<int, array{role:string, content:string}> $messages
     * @throws \RuntimeException from buildProvider() (no key in message).
     */
    public function structured(array $messages, string $schema, ?string $profileId = null): array
    {
        $provider = $this->buildProvider($profileId);
        $mapped   = $this->mapMessages($messages);
        $raw      = $provider->structured($mapped, $schema);
        return $this->normalizeStructuredResult($raw);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Map [['role'=>..,'content'=>..], ...] to php-agents message objects.
     * Unknown roles fall back to UserMessage (defensive).
     *
     * @param array<int, array{role:string, content:string}> $messages
     * @return array<int, object>
     */
    private function mapMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role    = is_array($m) ? (string) ($m['role'] ?? 'user') : 'user';
            $content = is_array($m) ? (string) ($m['content'] ?? '') : (string) $m;
            $out[] = match ($role) {
                'system'    => new SystemMessage($content),
                'assistant' => new AssistantMessage($content),
                default     => new UserMessage($content),
            };
        }
        return $out;
    }

    /**
     * Normalize php-agents structured() return to a decoded PHP array.
     *  1. array (Anthropic tool_use / OpenAIResponses) → use as-is.
     *  2. Response (openai/grok/gemini/mistral/ollama) → json_decode(->content).
     *  3. anything else → [].
     */
    private function normalizeStructuredResult(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw instanceof Response) {
            $decoded = json_decode($raw->content, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Ollama base URL: profile override → OLLAMA_HOST (+ /v1) → php-agents default.
     * php-agents' OllamaProvider strips /v1 internally for its native endpoints.
     */
    private function resolveOllamaBaseUrl(string $baseUrl): string
    {
        if ($baseUrl !== '') {
            return $baseUrl;
        }
        $host = getenv('OLLAMA_HOST');
        if ($host !== false && $host !== '') {
            return rtrim($host, '/') . '/v1';
        }
        return self::DEFAULT_BASE_URLS[self::PROVIDER_OLLAMA];
    }
}
