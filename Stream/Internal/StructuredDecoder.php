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

    /** A JSON object/array document as an array; scalars, invalid JSON and over-deep nesting give null. */
    public static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true, self::MAX_DEPTH);
        return is_array($decoded) ? $decoded : null;
    }

    /** array as-is; php-agents Response -> decoded content (or []); anything else -> []. */
    public static function normalize(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw instanceof Response) {
            return self::decode($raw->content) ?? [];
        }
        return [];
    }
}
