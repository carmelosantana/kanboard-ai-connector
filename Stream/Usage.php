<?php

namespace Kanboard\Plugin\AiConnector\Stream;

/** Token usage of one attempt, each count clamped to >= 0. */
final readonly class Usage
{
    public int $promptTokens;
    public int $completionTokens;
    public int $totalTokens;

    public function __construct(int $promptTokens, int $completionTokens, int $totalTokens)
    {
        $this->promptTokens = max(0, $promptTokens);
        $this->completionTokens = max(0, $completionTokens);
        $this->totalTokens = max(0, $totalTokens);
    }
}
