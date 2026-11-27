<?php

namespace Kanboard\Plugin\AiConnector\Stream\Internal;

use CarmeloSantana\PHPAgents\Provider\Response;

/**
 * The one rule for turning structured-output JSON into a PHP array, shared by
 * ProviderRegistry::structured() and streamed JSON documents. Loadable without
 * php-agents: Response is only touched via instanceof, which resolves lazily.
 */
final class StructuredDecoder
{
    private const MAX_DEPTH = 64;

    /** The most bytes of structured JSON AiConnector reads (256 KiB), streamed or blocking. */
    public const MAX_BYTES = 262144;

    /** A JSON object/array document as an array; scalars, invalid JSON and over-deep nesting give null. */
    public static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true, self::MAX_DEPTH);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * array as-is when it passes decode()'s depth rule, else []; php-agents Response -> decoded
     * content (or []); anything else -> [].
     */
    public static function normalize(mixed $raw): array
    {
        if (is_array($raw)) {
            return self::withinDepth($raw, 1) ? $raw : [];
        }
        if ($raw instanceof Response) {
            return self::decode($raw->content) ?? [];
        }
        return [];
    }

    /**
     * Whether $value nests fewer than MAX_DEPTH arrays, the rule json_decode() applies to text
     * ($depth is $value's own level). The walk stops at the first branch past the limit, so it
     * recurses at most MAX_DEPTH frames deep.
     */
    private static function withinDepth(array $value, int $depth): bool
    {
        if ($depth >= self::MAX_DEPTH) {
            return false;
        }
        foreach ($value as $item) {
            if (is_array($item) && ! self::withinDepth($item, $depth + 1)) {
                return false;
            }
        }
        return true;
    }
}
