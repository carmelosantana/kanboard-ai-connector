<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

use InvalidArgumentException;
use Kanboard\Plugin\AiConnector\Stream\Usage;

/** Terminal: the provider answered (or the attempt was cancelled after the reservation). */
final readonly class Finish implements Terminal
{
    public const REASONS = [
        'stop', 'tool_use', 'max_tokens', 'refusal', 'content_filter', 'context_window_exceeded',
        'incomplete', 'cancelled', 'invalid_output', 'output_too_large',
    ];

    public function __construct(
        public string $reason,
        public string $model,
        public ?Usage $usage,
        public ?array $result,
    ) {
        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unknown finish reason: $reason");
        }
    }
}
