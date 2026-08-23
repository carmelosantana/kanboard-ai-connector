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
