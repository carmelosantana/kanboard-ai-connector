<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

/** The model opens a tool call. */
final readonly class ToolCallStart
{
    public function __construct(
        public int $index,
        public string $id,
        public string $name,
    ) {
    }
}
