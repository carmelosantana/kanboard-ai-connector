<?php

namespace Kanboard\Plugin\AiConnector\Stream;

use InvalidArgumentException;

/** One streaming request. Malformed input is a programmer error and throws; every runtime outcome is an event. */
final readonly class AiRequest
{
    public function __construct(
        public string $feature,
        public string $attemptKey,
        public array $messages,
        public ?string $schema = null,
        public ?int $projectId = null,
        public ?string $pick = null,
        public ?AiCancellation $cancel = null,
        public float $idleTimeout = 60.0,
        public float $maxDuration = 300.0,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,64}\z/', $attemptKey) !== 1) {
            throw new InvalidArgumentException('The attempt key must be 1-64 characters of A-Z a-z 0-9 . _ -');
        }
        if ($messages === [] || !array_is_list($messages)) {
            throw new InvalidArgumentException('Messages must be a non-empty list');
        }
        foreach ($messages as $m) {
            if (!is_array($m) || !is_string($m['role'] ?? null) || !is_string($m['content'] ?? null)
                || !in_array($m['role'], ['system', 'user', 'assistant'], true)) {
                throw new InvalidArgumentException('Each message needs a role (system, user or assistant) and string content');
            }
        }
        if ($schema !== null && !is_array(json_decode($schema, true))) {
            throw new InvalidArgumentException('The schema must be a JSON object or array');
        }
    }

    /** Idle timeout in seconds, clamped to [1, 120]; non-finite reads as 60. */
    public function idleTimeout(): float
    {
        return is_finite($this->idleTimeout) ? min(120.0, max(1.0, $this->idleTimeout)) : 60.0;
    }

    /** Maximum duration in seconds, clamped to [1, 600]; non-finite reads as 300. */
    public function maxDuration(): float
    {
        return is_finite($this->maxDuration) ? min(600.0, max(1.0, $this->maxDuration)) : 300.0;
    }
}
