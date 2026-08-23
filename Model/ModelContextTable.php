<?php

namespace Kanboard\Plugin\AiConnector\Model;

/**
 * ModelContextTable — curated fallback map of model id/family -> max context window.
 *
 * Pure data + resolver. No container, no php-agents, no network. Used by
 * ModelCatalog when live discovery is unavailable/not applicable.
 *
 * Values are each model's TOTAL native context window (the same quantity live
 * Ollama /api/show reports), verified against primary sources on LAST_VERIFIED.
 * Ollama families use the conservative floor (under-reporting only makes budgeting
 * safer). Beta-only tiers are excluded (Claude Sonnet 4/4.5 = 200K standard).
 *
 * See docs/superpowers/specs/2026-08-23-model-context-token-api-design.md §8 for
 * the cited sources behind every value.
 *
 * @package Kanboard\Plugin\AiConnector\Model
 * @author  Carmelo Santana
 */
final class ModelContextTable
{
    /** Date every value below was verified against a primary source. */
    public const LAST_VERIFIED = '2026-08-23';

    /** Returned when provider is unknown/unsupported and nothing else matches. */
    public const GLOBAL_DEFAULT = 8192;

    /** ProviderRegistry provider type -> table partition. openai_responses shares openai. */
    private const PARTITION = [
        'openai_responses' => 'openai',
    ];

    /** Exact model id (lowercased) -> context window, per partition. */
    private const EXACT = [
        'anthropic' => [
            'claude-3-opus'        => 200000,
            'claude-3-5-sonnet'    => 200000,
            'claude-3-5-haiku'     => 200000,
            'claude-sonnet-4'      => 200000,
            'claude-opus-4'        => 200000,
            'claude-opus-4-1'      => 200000,
            'claude-sonnet-4-5'    => 200000,
            'claude-opus-4-5'      => 200000,
            'claude-haiku-4-5'     => 200000,
            'claude-opus-5'        => 1000000,
            'claude-sonnet-5'      => 1000000,
            'claude-fable-5'       => 1000000,
            'claude-mythos-5'      => 1000000,
            'claude-mythos-preview'=> 1000000,
        ],
        'openai' => [
            'gpt-4o'            => 128000,
            'gpt-4o-mini'       => 128000,
            'gpt-4.1'           => 1047576,
            'gpt-4.1-mini'      => 1047576,
            'gpt-4.1-nano'      => 1047576,
            'gpt-5'             => 400000,
            'gpt-5-mini'        => 400000,
            'gpt-5-nano'        => 400000,
            'gpt-5-pro'         => 400000,
            'gpt-5-chat-latest' => 128000,
            'gpt-5.6'           => 1050000,
            'gpt-5.6-sol'       => 1050000,
            'gpt-5.6-terra'     => 1050000,
            'gpt-5.6-luna'      => 1050000,
            'gpt-5.6-cyber'     => 400000,
            'o1'                => 200000,
            'o1-mini'           => 128000,
            'o1-pro'            => 200000,
            'o3'                => 200000,
            'o3-mini'           => 200000,
            'o3-pro'            => 200000,
            'o4-mini'           => 200000,
        ],
        'gemini' => [
            'gemini-1.5-pro'         => 2097152,
            'gemini-1.5-flash'       => 1048576,
            'gemini-2.0-flash'       => 1048576,
            'gemini-2.0-flash-lite'  => 1048576,
            'gemini-2.5-pro'         => 1048576,
            'gemini-2.5-flash'       => 1048576,
            'gemini-2.5-flash-lite'  => 1048576,
        ],
        'mistral' => [
            'mistral-small-2409'  => 32000,
            'mistral-medium-2508' => 128000,
            'mistral-medium-2604' => 256000,
        ],
        'grok' => [
            'grok-4'                    => 256000,
            'grok-4-latest'             => 256000,
            'grok-4-0709'               => 256000,
            'grok-code-fast-1'          => 256000,
            'grok-4-fast-reasoning'     => 2000000,
            'grok-4-fast-non-reasoning' => 2000000,
        ],
    ];

    /**
     * Family prefix (lowercased) -> context window, per partition.
     * Matched longest-prefix-first, so a more specific prefix always wins.
     */
    private const FAMILY = [
        'anthropic' => [
            'claude-3'          => 200000,
            'claude-sonnet-4-6' => 1000000,
            'claude-sonnet-4-7' => 1000000,
            'claude-sonnet-4-8' => 1000000,
            'claude-opus-4-6'   => 1000000,
            'claude-opus-4-7'   => 1000000,
            'claude-opus-4-8'   => 1000000,
            'claude-sonnet-4'   => 200000,
            'claude-opus-4'     => 200000,
            'claude-haiku-4'    => 200000,
            'claude-opus-5'     => 1000000,
            'claude-sonnet-5'   => 1000000,
            'claude-fable-5'    => 1000000,
            'claude-mythos'     => 1000000,
        ],
        'openai' => [
            'gpt-5.6' => 1050000,
            'gpt-5'   => 400000,
            'gpt-4.1' => 1047576,
            'gpt-4o'  => 128000,
            'o1'      => 200000,
            'o3'      => 200000,
            'o4'      => 200000,
        ],
        'gemini' => [
            'gemini-1.5-pro' => 2097152,
            'gemini'         => 1048576,
        ],
        'mistral' => [
            'mistral-large'  => 128000,
            'mistral-small'  => 128000,
            'mistral-medium' => 128000,
            'codestral'      => 128000,
            'ministral'      => 128000,
            'pixtral'        => 128000,
        ],
        'grok' => [
            'grok-4-fast' => 2000000,
            'grok-4'      => 256000,
            'grok-code'   => 256000,
            'grok-3'      => 131072,
            'grok-2'      => 131072,
        ],
        'ollama' => [
            'qwen3'           => 40960,
            'qwen2.5-coder'   => 32768,
            'qwen2.5'         => 32768,
            'llama3.1'        => 131072,
            'llama3.2'        => 131072,
            'llama3.3'        => 131072,
            'gemma2'          => 8192,
            'gemma3'          => 32768,
            'mixtral'         => 32768,
            'mistral'         => 32768,
            'phi3'            => 4096,
            'phi4'            => 16384,
            'deepseek-r1'     => 131072,
            'deepseek-v3'     => 131072,
            'codellama'       => 16384,
            'nomic-embed-text'=> 2048,
        ],
    ];

    /** Conservative per-partition default when no id/family matches. */
    private const PROVIDER_DEFAULT = [
        'anthropic' => 200000,
        'openai'    => 128000,
        'gemini'    => 1048576,
        'mistral'   => 128000,
        'grok'      => 131072,
        'ollama'    => 8192,
    ];

    /**
     * Resolve a max context window for a provider type + model id.
     * Order: exact(raw) -> exact(normalized) -> longest-prefix family ->
     * provider default -> global default. Never throws.
     */
    public static function resolve(string $provider, string $model): int
    {
        $partition = self::PARTITION[$provider] ?? $provider;
        $lower     = strtolower(trim($model));

        $exact = self::EXACT[$partition] ?? [];
        if ($lower !== '' && isset($exact[$lower])) {
            return $exact[$lower];
        }

        $candidate = self::normalizeCandidate($partition, $lower);
        if ($candidate !== '' && isset($exact[$candidate])) {
            return $exact[$candidate];
        }

        $families = self::FAMILY[$partition] ?? [];
        $bestKey  = null;
        foreach ($families as $key => $window) {
            if ($candidate !== '' && str_starts_with($candidate, $key)) {
                if ($bestKey === null || strlen($key) > strlen($bestKey)) {
                    $bestKey = $key;
                }
            }
        }
        if ($bestKey !== null) {
            return $families[$bestKey];
        }

        return self::PROVIDER_DEFAULT[$partition] ?? self::GLOBAL_DEFAULT;
    }

    /**
     * Reduce a concrete id to a family-matchable candidate.
     * Ollama: drop the ":tag" suffix. Others: strip a trailing -latest,
     * -0, or -<8-digit date>.
     */
    private static function normalizeCandidate(string $partition, string $lower): string
    {
        if ($lower === '') {
            return '';
        }
        if ($partition === 'ollama') {
            $pos = strpos($lower, ':');
            return $pos === false ? $lower : substr($lower, 0, $pos);
        }
        $c = preg_replace('/-(latest|\d{8})$/', '', $lower);
        $c = preg_replace('/-0$/', '', (string) $c);
        return (string) $c;
    }
}
