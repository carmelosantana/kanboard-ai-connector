<?php

namespace Kanboard\Plugin\AiConnector\Stream;

/** Host-owned cancellation flag, polled by AiStream on every event. */
interface AiCancellation
{
    public function isCancelled(): bool;
}
