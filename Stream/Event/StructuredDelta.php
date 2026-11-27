<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

/** A fragment of the JSON document (structured stream). Untrusted model output. */
final readonly class StructuredDelta
{
    public function __construct(
        public string $json,
    ) {
    }
}
