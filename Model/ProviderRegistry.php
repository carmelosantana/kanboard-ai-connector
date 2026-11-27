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
        self::PROVIDER_ANTHROPIC        => 'claude-sonnet-5-5',
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
        self::PROVIDER_OPENAI           => 'https://api.openai.com/v1',
        self::PROVIDER_OPENAI_RESPONSES => 'https://api.openai.com/v1',
        self::PROVIDER_OLLAMA           => 'http://localhost:11434/v1',
    ];

    /** Provider types whose requests go to the profile's base URL. */
    public const BASE_URL_PROVIDERS = [self::PROVIDER_OPENAI, self::PROVIDER_OPENAI_RESPONSES, self::PROVIDER_OLLAMA];

    /** Config keys + masking sentinel. */
    public const PROFILES_KEY   = 'aiconnector_profiles';
    public const DEFAULT_KEY    = 'aiconnector_default';
    public const KEY_PREFIX     = 'aiconnector_key_';
    public const KEY_PLACEHOLDER = '••••••••';

    /** Hook every consumer appends its AI features to: fn () => [['id','label','plugin'], …]. */
    public const FEATURES_HOOK = 'aiconnector:features';
    public const FEATURE_ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}\z/';

    // ── Profile reads ─────────────────────────────────────────────────────────

    /**
     * Full profile structs incl. base_url. Order = storage order.
     *
     * @return array<int, array{id:string,label:string,provider:string,model:string,base_url:string,available:bool}>
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
                'available' => (bool) ($p['available'] ?? false),
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

    /**
     * Every AI feature registered on FEATURES_HOOK, keyed by id. Ids must match
     * FEATURE_ID_PATTERN (the AiQuota feature names); a duplicate id keeps the first entry;
     * a listener that throws is logged and skipped, so one plugin cannot break the list.
     *
     * @return array<string, array{id:string,label:string,plugin:string}>
     */
    public function features(): array
    {
        $out = [];
        foreach ($this->hook->getListeners(self::FEATURES_HOOK) as $listener) {
            if (! is_callable($listener)) {
                continue;
            }
            try {
                $entries = $listener();
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[AiConnector] %s listener failed: %s', self::FEATURES_HOOK, get_class($e)));
                continue;
            }
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $e) {
                if (! is_array($e) || ! is_string($e['id'] ?? null) || ! is_string($e['label'] ?? null) || ! is_string($e['plugin'] ?? null)) {
                    continue;
                }
                if (! preg_match(self::FEATURE_ID_PATTERN, $e['id']) || isset($out[$e['id']])) {
                    continue;
                }
                $out[$e['id']] = ['id' => $e['id'], 'label' => $e['label'], 'plugin' => $e['plugin']];
            }
        }
        return $out;
    }

    /** Routing skips already logged by this registry (one log line per skip per request). */
    private array $loggedSkips = [];

    /**
     * The profile id an AI request for $feature uses, or '' when no level has a ready profile.
     * Order: a locked feature uses its assignment, then its plugin's, then the global default;
     * otherwise a valid pick, then the project's profile, the feature's, the plugin's and the
     * global default. A pick counts only when the picker would be shown for this feature and
     * project and the pick is one of the offered profiles; any other pick is ignored. A level
     * whose profile is missing or not ready is skipped and logged.
     */
    public function resolve(string $feature, ?int $projectId = null, ?string $pick = null): string
    {
        $routing = new ModelRouting($this->container);
        $assigned = $routing->assignments();
        $registered = $this->features()[$feature] ?? null;
        $locked = $registered !== null && in_array($feature, $assigned['locked'], true);

        $offered = $this->offeredIds();
        $levels = [];
        // Picks count only for registered features, so a mistyped feature cannot bypass routing.
        if ($registered !== null && ! $locked && $pick !== null && $pick !== ''
            && $this->pickAllowed($feature, $projectId, $locked) && in_array($pick, $offered, true)) {
            $levels['pick'] = $pick;
        }
        if (! $locked && $projectId !== null) {
            $projectProfile = $routing->projectProfile($projectId);
            // A project profile must still be offered: an admin can withdraw a profile later.
            if ($projectProfile !== '' && ! in_array($projectProfile, $offered, true) && $this->profileReady($projectProfile)) {
                $this->logSkip($feature, 'project', $projectProfile, 'not offered');
            } else {
                $levels['project'] = $projectProfile;
            }
        }
        if ($registered !== null) {
            $levels['feature'] = $assigned['features'][$feature] ?? '';
            $levels['plugin'] = $assigned['plugins'][$registered['plugin']] ?? '';
        }
        $levels['global'] = $this->getDefaultProfileId();

        foreach ($levels as $level => $id) {
            if ($id === '') {
                continue;
            }
            if ($this->profileReady($id)) {
                return $id;
            }
            $this->logSkip($feature, $level, $id);
        }
        return '';
    }

    /**
     * What the shared picker shows for $feature: whether to show it, the offered profiles
     * (id/label/provider/model only — never a key or base URL) and the preselected profile,
     * which is what resolve() would choose without a pick.
     *
     * @return array{show: bool, profiles: list<array{id:string,label:string,provider:string,model:string}>, selected: string}
     */
    public function pickerOptions(string $feature, ?int $projectId = null): array
    {
        $registered = isset($this->features()[$feature]);
        $locked = $registered
            && in_array($feature, (new ModelRouting($this->container))->assignments()['locked'], true);
        return [
            'show' => $registered && $this->pickAllowed($feature, $projectId, $locked),
            'profiles' => $this->offeredProfiles(),
            'selected' => $this->resolve($feature, $projectId),
        ];
    }

    /**
     * Plugin and feature assignments whose profile is deleted or not ready, for the settings page.
     *
     * @return list<array{level:string,key:string,profile:string,reason:string}>
     */
    public function brokenAssignments(): array
    {
        $out = [];
        foreach ((new ModelRouting($this->container))->assignments() as $level => $map) {
            if ($level === 'locked') {
                continue;
            }
            foreach ($map as $key => $id) {
                if (! $this->profileReady($id)) {
                    $out[] = ['level' => $level, 'key' => $key, 'profile' => $id, 'reason' => $this->findProfile($id) === null ? 'missing' : 'not ready'];
                }
            }
        }
        return $out;
    }

    /**
     * The profiles people and project managers may choose (ready, and marked available or the
     * global default), as listProfiles() shapes them: no key, no base URL.
     *
     * @return list<array{id:string,label:string,provider:string,model:string}>
     */
    public function offeredProfiles(): array
    {
        $offered = $this->offeredIds();
        return array_values(array_filter($this->listProfiles(), fn (array $p) => in_array($p['id'], $offered, true)));
    }

    /** Ready profiles a person may pick: those marked available, plus the global default. */
    private function offeredIds(): array
    {
        $default = $this->getDefaultProfileId();
        $ids = [];
        foreach ($this->getProfiles() as $p) {
            if (($p['available'] || $p['id'] === $default) && $this->profileReady($p['id'])) {
                $ids[] = $p['id'];
            }
        }
        return $ids;
    }

    /** Whether the picker is shown: unlocked, two or more offered, and the project allows switching. */
    private function pickAllowed(string $feature, ?int $projectId, bool $locked): bool
    {
        if ($locked || count($this->offeredIds()) < 2) {
            return false;
        }
        return $projectId === null || (new ModelRouting($this->container))->membersMaySwitch($projectId);
    }

    private function logSkip(string $feature, string $level, string $id, ?string $reason = null): void
    {
        $reason ??= $this->findProfile($id) === null ? 'missing' : 'not ready';
        $clean = fn (string $v) => preg_replace('/[^a-z0-9._-]/i', '', $v);
        $line = sprintf('[AiConnector] routing: %s skipped %s profile %s (%s)', $clean($feature), $level, $clean($id), $reason);
        if (! isset($this->loggedSkips[$line])) {
            $this->loggedSkips[$line] = true;
            $this->logger->info($line);
        }
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
            if ($this->profileReady($p['id'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when profile $id exists and has a resolvable key (stored/env) or is keyless.
     * No network call.
     */
    public function profileReady(string $id): bool
    {
        $p = $this->findProfile($id);
        if ($p === null) {
            return false;
        }
        if (in_array($p['provider'], self::KEYLESS, true)) {
            return true;
        }
        $stored = (string) $this->configModel->get(self::KEY_PREFIX . $p['id'], '');
        return $this->resolveKey($p['provider'], $stored) !== '';
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
        $stored  = (string) $this->configModel->get(self::KEY_PREFIX . $id, '');
        $key     = $this->resolveKey($type, $stored);

        // Base-URL providers: SSRF-check the URL and pin the client to the
        // checked address. Throws UnsafeUrlException (no key in the message).
        if (in_array($type, self::BASE_URL_PROVIDERS, true)) {
            $baseUrl = $this->effectiveBaseUrl($type, $profile['base_url']);
            $client  = UrlGuard::fromConfig($this->configModel)->httpClient($baseUrl, ['timeout' => 300]);
        }

        return match ($type) {
            self::PROVIDER_ANTHROPIC => new AnthropicProvider(model: $model, apiKey: $key),
            self::PROVIDER_OPENAI => new OpenAICompatibleProvider(model: $model, baseUrl: $baseUrl, apiKey: $key, httpClient: $client),
            self::PROVIDER_OPENAI_RESPONSES => new OpenAIResponsesProvider(model: $model, baseUrl: $baseUrl, apiKey: $key, httpClient: $client),
            self::PROVIDER_GROK    => new XAIProvider(model: $model, apiKey: $key),
            self::PROVIDER_GEMINI  => new GeminiProvider(model: $model, apiKey: $key),
            self::PROVIDER_MISTRAL => new MistralProvider(model: $model, apiKey: $key),
            self::PROVIDER_OLLAMA  => new OllamaProvider(model: $model, baseUrl: $baseUrl, httpClient: $client),
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

    /** '' (use the default), or an absolute http(s) URL with a host and no credentials. */
    public static function isValidBaseUrl(string $baseUrl): bool
    {
        if ($baseUrl === '') {
            return true;
        }
        $parts = parse_url($baseUrl);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && filter_var($baseUrl, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Where a profile's requests (and its key) go: the provider type, plus the
     * scheme://host:port of the base URL for providers that use one.
     */
    public static function endpoint(string $provider, string $baseUrl): string
    {
        if (! in_array($provider, self::BASE_URL_PROVIDERS, true)) {
            return $provider;
        }
        $url = $baseUrl !== '' ? $baseUrl : (self::DEFAULT_BASE_URLS[$provider] ?? '');
        $parts = $url !== '' ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['host'] ?? '') === '') {
            return $provider;
        }
        $origin = strtolower(($parts['scheme'] ?? '') . '://' . $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }
        return $provider . '|' . $origin;
    }

    /**
     * The URL a base-URL provider's requests actually go to: the profile's
     * base URL, else (Ollama) OLLAMA_HOST + /v1, else the provider default.
     * php-agents' OllamaProvider strips /v1 internally for its native endpoints.
     */
    public function effectiveBaseUrl(string $provider, string $baseUrl): string
    {
        if ($baseUrl !== '') {
            return $baseUrl;
        }
        if ($provider === self::PROVIDER_OLLAMA) {
            $host = getenv('OLLAMA_HOST');
            if ($host !== false && $host !== '') {
                return rtrim($host, '/') . '/v1';
            }
        }
        return self::DEFAULT_BASE_URLS[$provider] ?? '';
    }
}
