<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

/** Answer text (text stream). Untrusted model output. */
final readonly class TextDelta
{
    public function __construct(
        public string $text,
    ) {
    }
}
