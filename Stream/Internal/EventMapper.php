<?php

namespace Kanboard\Plugin\AiConnector\Stream\Internal;

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish as UpstreamFinish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta as UpstreamReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta as UpstreamTextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use Kanboard\Plugin\AiConnector\Stream\Event\Error;
use Kanboard\Plugin\AiConnector\Stream\Event\Finish;
use Kanboard\Plugin\AiConnector\Stream\Event\ReasoningDelta;
use Kanboard\Plugin\AiConnector\Stream\Event\TextDelta;
use Kanboard\Plugin\AiConnector\Stream\Event\ToolCallEnd;
use Kanboard\Plugin\AiConnector\Stream\Event\ToolCallStart;
use Kanboard\Plugin\AiConnector\Stream\Usage;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

/**
 * php-agents stream events onto AiConnector's, per the spec's terminal mapping. Stateless: which
 * tool calls to drop is AiStream's call (isValidToolCall()), since it spans a Start and its End.
 */
final class EventMapper
{
    /** The non-terminal events: deltas and tool calls; anything else (ToolInputDelta, Tick) is null. */
    public static function map(StreamEvent $e): ?object
    {
        return match (true) {
            $e instanceof UpstreamTextDelta => new TextDelta($e->delta),
            $e instanceof UpstreamReasoningDelta => new ReasoningDelta($e->delta),
            $e instanceof ToolInputStart => new ToolCallStart($e->index, $e->id, $e->name),
            $e instanceof ToolInputEnd => new ToolCallEnd($e->index, $e->id, $e->name, $e->arguments),
            default => null,
        };
    }

    /** The terminal event for the provider's Finish; the model is the configured one, never the provider's. */
    public static function finish(UpstreamFinish $f, string $configuredModel): Finish|Error
    {
        $reason = match ($f->reason) {
            ProviderFinishReason::Stop => 'stop',
            ProviderFinishReason::ToolUse => 'tool_use',
            ProviderFinishReason::MaxTokens => 'max_tokens',
            ProviderFinishReason::Refusal => 'refusal',
            ProviderFinishReason::ContentFilter => 'content_filter',
            ProviderFinishReason::ContextWindowExceeded => 'context_window_exceeded',
            ProviderFinishReason::PauseTurn => 'incomplete',
            ProviderFinishReason::Error => null,
        };
        if ($reason === null) {
            return self::error('provider_error');
        }
        $usage = $f->usage === null ? null
            : new Usage($f->usage->promptTokens, $f->usage->completionTokens, $f->usage->totalTokens);

        return new Finish($reason, $configuredModel, $usage, null);
    }

    /** A timeout anywhere in the exception chain is `timeout`; anything else is `provider_error`. */
    public static function exception(\Throwable $e): Error
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TimeoutExceptionInterface) {
                return self::error('timeout');
            }
        }
        return self::error('provider_error');
    }

    /** An Error with its fixed sentence: never a provider's or an exception's text. */
    public static function error(string $code): Error
    {
        return new Error($code, $code === 'timeout'
            ? t('The AI request timed out. Try again.')
            : t('The AI provider failed to answer. Try again later.'));
    }

    /** A tool name of 1-64 A-Z a-z 0-9 _ . - and an id of 1-128 printable ASCII characters. */
    public static function isValidToolCall(string $id, string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,64}\z/', $name) === 1
            && preg_match('/^[\x20-\x7E]{1,128}\z/', $id) === 1;
    }
}
