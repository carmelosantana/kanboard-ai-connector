<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

/** A tool call's arguments are complete and decoded. Untrusted model output. */
final readonly class ToolCallEnd
{
    public function __construct(
        public int $index,
        public string $id,
        public string $name,
        public array $arguments,
    ) {
    }
}
