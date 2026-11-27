<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

/** Provider reasoning, where sent. Untrusted model output. */
final readonly class ReasoningDelta
{
    public function __construct(
        public string $text,
    ) {
    }
}
