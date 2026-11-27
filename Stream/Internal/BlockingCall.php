<?php

namespace Kanboard\Plugin\AiConnector\Stream\Internal;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Contract\UsageReporting;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage as UpstreamUsage;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use Kanboard\Plugin\AiConnector\Stream\AiCancellation;
use Kanboard\Plugin\AiConnector\Stream\Event\Error;
use Kanboard\Plugin\AiConnector\Stream\Event\Finish;
use Kanboard\Plugin\AiConnector\Stream\Event\ReasoningDelta;
use Kanboard\Plugin\AiConnector\Stream\Event\Started;
use Kanboard\Plugin\AiConnector\Stream\Event\StructuredDelta;
use Kanboard\Plugin\AiConnector\Stream\Event\TextDelta;
use Kanboard\Plugin\AiConnector\Stream\Event\ToolCallEnd;
use Kanboard\Plugin\AiConnector\Stream\Event\ToolCallStart;

/**
 * One blocking chat() or structured() call, yielded in the stream's event shape: the whole answer
 * as one delta, tool calls as Start/End pairs, then exactly one terminal event. Used for
 * providers without native streaming and as the fallback when an endpoint rejects the streamed
 * request. The token is checked once, before the call; there is no Progress during it.
 *
 * Usage comes from a php-agents UsageListener attached for the one call when the provider
 * reports usage, else from the Response.
 */
final class BlockingCall
{
    /** @var \Closure(\Throwable): Error maps (and logs) a thrown call */
    private \Closure $onException;

    /** @var \Closure(string): void logs a provider finish error */
    private \Closure $onFinishError;

    /** @var \Closure(int): void logs a dropped tool call by index */
    private \Closure $onDroppedTool;

    public function __construct(
        private string $model,
        ?\Closure $onException = null,
        ?\Closure $onFinishError = null,
        ?\Closure $onDroppedTool = null,
    ) {
        $this->onException = $onException ?? fn (\Throwable $e): Error => EventMapper::exception($e);
        $this->onFinishError = $onFinishError ?? static function (string $detail): void {};
        $this->onDroppedTool = $onDroppedTool ?? static function (int $index): void {};
    }

    /**
     * Started(streaming: false), the token checked once (cancelled: Finish(cancelled), no call),
     * then the answer.
     *
     * @return \Generator<int, object>
     */
    public function text(ProviderInterface $provider, array $messages, ?AiCancellation $cancel = null): \Generator
    {
        yield new Started($this->model, false);
        if ($cancel?->isCancelled()) {
            yield new Finish('cancelled', $this->model, null, null);
            return;
        }
        yield from $this->textAnswer($provider, $messages);
    }

    /**
     * The answer without Started: for the fallback, whose consumer already holds the stream's
     * Started.
     *
     * @return \Generator<int, object>
     */
    public function textAnswer(ProviderInterface $provider, array $messages): \Generator
    {
        $usage = self::recorder();
        try {
            $response = self::withListener($provider, $usage)->chat($messages, [], []);
        } catch (\Throwable $e) {
            yield ($this->onException)($e);
            return;
        }

        if ($response->reasoning !== '') {
            yield new ReasoningDelta($response->reasoning);
        }
        if ($response->content !== '') {
            yield new TextDelta($response->content);
        }
        foreach (array_values($response->toolCalls) as $index => $call) {
            if (! EventMapper::isValidToolCall($call->id, $call->name)) {
                ($this->onDroppedTool)($index);
                continue;
            }
            yield new ToolCallStart($index, $call->id, $call->name);
            yield new ToolCallEnd($index, $call->id, $call->name, $call->arguments);
        }

        yield $this->terminal($response->finishReason, self::usageOf($usage->report, $response->usage));
    }

    /** EventMapper's terminal for a finish reason; a provider finish error is logged. */
    private function terminal(ProviderFinishReason $reason, ?UpstreamUsage $usage): Finish|Error
    {
        $terminal = EventMapper::terminal($reason, $usage, $this->model);
        if ($terminal instanceof Error) {
            ($this->onFinishError)('provider finish error');
        }
        return $terminal;
    }

    /**
     * Started(streaming: false), one StructuredDelta under the 256 KiB cap, then Finish: `stop`
     * with the decoded array, `invalid_output` when it is not a non-empty array, or
     * `output_too_large`. A Response that did not finish `stop` gets the stream path's terminal
     * for its finish reason instead, with no StructuredDelta and no result.
     *
     * @return \Generator<int, object>
     */
    public function structured(ProviderInterface $provider, array $messages, string $schema, ?AiCancellation $cancel = null): \Generator
    {
        yield new Started($this->model, false);
        if ($cancel?->isCancelled()) {
            yield new Finish('cancelled', $this->model, null, null);
            return;
        }

        $usage = self::recorder();
        try {
            $raw = self::withListener($provider, $usage)->structured($messages, $schema, []);
        } catch (\Throwable $e) {
            yield ($this->onException)($e);
            return;
        }

        if ($raw instanceof Response && $raw->finishReason !== ProviderFinishReason::Stop) {
            // Not a finished document: the stream path's terminal, no delta and no result.
            yield $this->terminal($raw->finishReason, self::usageOf($usage->report, $raw->usage));
            return;
        }

        $usageOut = EventMapper::usage(self::usageOf($usage->report, $raw instanceof Response ? $raw->usage : null));
        $content = match (true) {
            $raw instanceof Response => $raw->content,
            is_string($raw) => $raw,
            default => (string) json_encode($raw),
        };
        if (strlen($content) > StructuredDecoder::MAX_BYTES) {
            yield new Finish('output_too_large', $this->model, $usageOut, null);
            return;
        }

        $decoded = is_string($raw) ? (StructuredDecoder::decode($raw) ?? []) : StructuredDecoder::normalize($raw);
        $json = $decoded !== [] ? (string) json_encode($decoded) : $content;
        if (strlen($json) > StructuredDecoder::MAX_BYTES) {
            yield new Finish('output_too_large', $this->model, $usageOut, null);
            return;
        }
        if ($json !== '') {
            yield new StructuredDelta($json);
        }
        yield $decoded !== []
            ? new Finish('stop', $this->model, $usageOut, $decoded)
            : new Finish('invalid_output', $this->model, $usageOut, null);
    }

    /** A UsageListener that keeps the call's report in ->report. */
    private static function recorder(): UsageListener
    {
        return new class implements UsageListener {
            public ?UsageReport $report = null;
            public function onUsage(UsageReport $report): void
            {
                $this->report = $report;
            }
        };
    }

    private static function withListener(ProviderInterface $provider, UsageListener $listener): ProviderInterface
    {
        return $provider instanceof UsageReporting ? $provider->withUsageListener($listener) : $provider;
    }

    /** The listener's report wins; else the Response's usage. */
    private static function usageOf(?UsageReport $report, ?UpstreamUsage $fallback): ?UpstreamUsage
    {
        return $report?->usage ?? $fallback;
    }
}
