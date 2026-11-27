<?php

namespace Kanboard\Plugin\AiConnector\Model;

/**
 * What AiQuota::reserve() decided: ok with the attempt's id, or a refusal code with the text
 * to show the person.
 */
final readonly class Reservation
{
    public const OK = 'ok';
    public const TOO_LONG = 'too_long';
    public const DUPLICATE_ATTEMPT = 'duplicate_attempt';
    public const TOO_MANY_RUNNING = 'too_many_running';
    public const QUOTA = 'quota';
    public const UNAVAILABLE = 'unavailable';
    public const NOT_SIGNED_IN = 'not_signed_in';

    public function __construct(
        public string $outcome,
        public ?string $message = null,
        public ?int $attemptId = null,
    ) {}

    public function ok(): bool
    {
        return $this->outcome === self::OK;
    }
}
