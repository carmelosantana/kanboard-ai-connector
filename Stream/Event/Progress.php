<?php

namespace Kanboard\Plugin\AiConnector\Stream\Event;

/** Heartbeat, at most once a second while the stream is idle or flowing. */
final readonly class Progress
{
    public function __construct(
        public int $elapsedMs,
        public int $receivedChars,
    ) {
    }
}
