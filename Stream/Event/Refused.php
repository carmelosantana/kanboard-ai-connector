<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

use InvalidArgumentException;

/** Terminal: no provider request was made and nothing was charged (quota and too_long are logged, not counted). */
final readonly class Refused implements Terminal
{
    public const CODES = [
        'not_ready', 'not_signed_in', 'unknown_feature', 'no_profile', 'setup_failed', 'too_long',
        'duplicate_attempt', 'too_many_running', 'quota', 'unavailable', 'cancelled',
    ];

    public function __construct(
        public string $code,
        public string $message,
    ) {
        if (!in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException("Unknown refusal code: $code");
        }
    }
}
