<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

use InvalidArgumentException;

/** Terminal: the attempt was charged and failed. (Inside this namespace, PHP's own error class is \\Error.) */
final readonly class Error implements Terminal
{
    public const CODES = ['provider_error', 'timeout'];

    public function __construct(
        public string $code,
        public string $message,
    ) {
        if (!in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException("Unknown error code: $code");
        }
    }
}
