<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

/** The attempt is reserved and the request is about to go. */
final readonly class Started
{
    public function __construct(
        public string $model,
        public bool $streaming,
    ) {
    }
}
