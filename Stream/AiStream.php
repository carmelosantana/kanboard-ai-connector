<?php

namespace Kanboard\Plugin\AiConnector\Stream;

use Kanboard\Core\Base;
use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish as UpstreamFinish;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use Kanboard\Plugin\AiConnector\Stream\Event\Error;
use Kanboard\Plugin\AiConnector\Stream\Event\Finish;
use Kanboard\Plugin\AiConnector\Stream\Event\Progress;
use Kanboard\Plugin\AiConnector\Stream\Event\ReasoningDelta;
use Kanboard\Plugin\AiConnector\Stream\Event\Refused;
use Kanboard\Plugin\AiConnector\Stream\Event\Started;
use Kanboard\Plugin\AiConnector\Stream\Event\TextDelta;
use Kanboard\Plugin\AiConnector\Stream\Internal\BlockingCall;
use Kanboard\Plugin\AiConnector\Stream\Internal\EventMapper;
use Kanboard\Plugin\AiConnector\Stream\Internal\MessageMapper;
use Pimple\Container;

/**
 * The suite's streaming entry point:
 *
 *   foreach ((new AiStream($this->container))->events($request) as $event) { match on the class }
 *
 * Before any provider request, events() checks, in order: the gate (a ready profile), the
 * signed-in user, the registered feature, routing, building the provider, the cancellation
 * token, and one atomic AiQuota reservation. Each refusal is a single Refused event with a fixed
 * sentence; details go to the log only, sanitized. Nothing a request or provider does throws:
 * only programmer errors (and AiQuota's "inside a transaction" LogicException) do.
 *
 * No php-agents type appears in a public signature, so loading this class needs no php-agents
 * autoloader.
 */
class AiStream extends Base
{
    /** @var callable(): float seconds, for progress timing */
    private $clock;

    private ?ProviderRegistry $registry = null;

    public function __construct(Container $container, private ?AiQuota $quota = null, ?callable $clock = null)
    {
        parent::__construct($container);
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    public function setRegistryForTesting(ProviderRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /** @return \Generator<int, object> events, ending with exactly one terminal event when drained */
    public function events(AiRequest $request): \Generator
    {
        $registry = $this->registry ?? new ProviderRegistry($this->container);
        $feature = $request->feature;

        if (! $registry->isReady()) {
            yield $this->refuse('not_ready', 0, $feature);
            return;
        }

        $userId = (int) $this->userSession->getId();
        if ($userId <= 0) {
            yield $this->refuse('not_signed_in', 0, $feature);
            return;
        }

        if (! array_key_exists($feature, $registry->features())) {
            yield $this->refuse('unknown_feature', $userId, $feature);
            return;
        }

        $profileId = $registry->resolve($feature, $request->projectId, $request->pick);
        if ($profileId === '') {
            yield $this->refuse('no_profile', $userId, $feature);
            return;
        }

        try {
            $provider = $registry->buildProvider($profileId);
        } catch (\Throwable $e) {
            yield $this->refuse('setup_failed', $userId, $feature, self::shortClass($e));
            return;
        }

        if ($request->cancel?->isCancelled()) {
            yield $this->refuse('cancelled', $userId, $feature);
            return;
        }

        $input = array_column($request->messages, 'content');
        if ($request->schema !== null) {
            $input[] = $request->schema;
        }
        $quota = $this->quota ?? new AiQuota($this->container);
        $reservation = $quota->reserve($userId, $feature, $input, $request->attemptKey, $request->maxDuration());
        if (! $reservation->ok()) {
            // AiQuota already logged its refusal: one line per refusal.
            yield $this->refuse($reservation->outcome, $userId, $feature, null, $reservation->message, log: false);
            return;
        }

        $finished = false;
        try {
            $profile = $registry->findProfile($profileId) ?? ['provider' => '', 'model' => ''];
            $model = $profile['model'] !== '' ? $profile['model'] : (ProviderRegistry::DEFAULT_MODELS[$profile['provider']] ?? '');
            foreach ($this->stream($provider, $request, $model, $userId) as $event) {
                $finished = $event instanceof Finish;
                yield $event;
            }
        } finally {
            // Drained, abandoned or thrown: the attempt closes exactly once, here.
            $quota->closeAttempt((int) $reservation->attemptId, $finished ? 'finished' : 'failed');
        }
    }

    /**
     * Started, the mapped provider events and Progress heartbeats, then exactly one terminal
     * event (Finish or Error), after which nothing is yielded. The token is checked on every
     * upstream event, ticks included; a cancel before the provider's Finish stops reading, which
     * abandons the php-agents generator and so cancels its HTTP request. Reading stops at the
     * provider's Finish too: a cancel after it changes nothing.
     *
     * A request with a schema takes the structured path (structured()). A provider that is not
     * an AbstractProvider gets one blocking call instead. A streamed
     * request rejected as a request-shape error before any output gets one blocking call on the
     * same attempt and charge (BlockingCall, no second Started).
     *
     * @return \Generator<int, object>
     */
    private function stream(object $provider, AiRequest $request, string $model, int $userId): \Generator
    {
        $started = ($this->clock)();
        $messages = MessageMapper::toPhpAgents($request->messages);
        $blocking = new BlockingCall(
            $model,
            onException: fn (\Throwable $e): Error => $this->failure($e, $started, $request, $userId),
            onFinishError: fn (string $detail) => $this->logFailure('provider_error', $userId, $request->feature, $detail),
            onDroppedTool: fn (int $index) => $this->logDroppedTool($userId, $request->feature, $index),
        );

        if ($request->schema !== null) {
            yield from $this->structured($provider, $blocking, $messages, $request);
            return;
        }
        if (! $provider instanceof AbstractProvider) {
            yield from $blocking->text($provider, $messages, $request->cancel);
            return;
        }

        yield new Started($model, true);
        if ($request->cancel?->isCancelled()) {
            yield new Finish('cancelled', $model, null, null);
            return;
        }

        $provider = $provider->withRequestLimits(new RequestLimits(
            idleTimeout: $request->idleTimeout(),
            maxDuration: $request->maxDuration(),
            tickInterval: 0.25,
        ));
        $lastProgress = $started;
        $receivedChars = 0;
        $dropped = [];
        $terminal = null;
        $output = false;
        $rejected = null;

        try {
            foreach ($provider->streamEvents($messages, [], []) as $event) {
                $output = $output || ! $event instanceof Tick;
                if ($event instanceof UpstreamFinish) {
                    // The provider's last event: take it and stop reading.
                    $terminal = EventMapper::finish($event, $model);
                    if ($terminal instanceof Error) {
                        $this->logFailure('provider_error', $userId, $request->feature, 'provider finish error');
                    }
                    break;
                }
                if ($request->cancel?->isCancelled()) {
                    $terminal = new Finish('cancelled', $model, null, null);
                    break;
                }

                if ($event instanceof ToolInputStart || $event instanceof ToolInputEnd) {
                    if (isset($dropped[$event->index]) || ! EventMapper::isValidToolCall($event->id, $event->name)) {
                        if (! isset($dropped[$event->index])) {
                            $dropped[$event->index] = true;
                            $this->logDroppedTool($userId, $request->feature, $event->index);
                        }
                        continue;
                    }
                }

                $mapped = EventMapper::map($event);
                if ($mapped instanceof TextDelta || $mapped instanceof ReasoningDelta) {
                    $receivedChars += mb_strlen($mapped->text, 'UTF-8');
                }
                if ($mapped !== null) {
                    yield $mapped;
                }

                $now = ($this->clock)();
                if ($now - $lastProgress >= 1.0) {
                    $lastProgress = $now;
                    yield new Progress((int) round(($now - $started) * 1000), $receivedChars);
                }
            }
        } catch (\Throwable $e) {
            if ($request->cancel?->isCancelled()) {
                // Cancelled before a Finish arrived: whatever the request then threw, it was cancelled.
                $terminal = new Finish('cancelled', $model, null, null);
            } else {
                // Rejected before any output: a protocol fallback, not a retry.
                $rejected = $output ? null : EventMapper::rejectedStatus($e);
                $terminal = $rejected === null ? $this->failure($e, $started, $request, $userId) : null;
            }
        }

        if ($rejected !== null) {
            $this->logger->info(sprintf('[AiConnector] AI stream: streaming rejected (HTTP %d), blocking fallback: user %d, feature %s',
                $rejected, $userId, self::safeFeature($request->feature)));
            // A blocking answer sends nothing until it is complete, so the idle limit is the time
            // left; the maximum duration counts from Started, not from the fallback.
            $remaining = max(1.0, $request->maxDuration() - (($this->clock)() - $started));
            $provider = $provider->withRequestLimits(self::blockingLimits($remaining));
            yield from $blocking->textAnswer($provider, $messages);
            return;
        }
        if ($terminal === null) {
            $this->logFailure('provider_error', $userId, $request->feature, 'no finish');
            $terminal = EventMapper::error('provider_error');
        }
        yield $terminal;
    }

    /**
     * The structured path: a request with a schema never goes through streamEvents(). One
     * blocking structured() call (BlockingCall), whose limits are the fallback's rule: a blocking
     * answer sends nothing until it is done, so idle = max = the request's maximum duration.
     *
     * @return \Generator<int, object>
     */
    private function structured(object $provider, BlockingCall $blocking, array $messages, AiRequest $request): \Generator
    {
        if ($provider instanceof AbstractProvider) {
            $provider = $provider->withRequestLimits(self::blockingLimits($request->maxDuration()));
        }

        if (interface_exists('CarmeloSantana\PHPAgents\Contract\StructuredStreaming')
            && $provider instanceof \CarmeloSantana\PHPAgents\Contract\StructuredStreaming) {
            // TASK 10: replace with the streamed structured call (php-agents 0.21 structuredEvents()).
            // Until then a StructuredStreaming provider takes the same blocking call as any other.
            yield from $blocking->structured($provider, $messages, (string) $request->schema, $request->cancel);
            return;
        }

        yield from $blocking->structured($provider, $messages, (string) $request->schema, $request->cancel);
    }

    /** Limits for one blocking call: no bytes arrive until it is done, so idle = max = $seconds. */
    private static function blockingLimits(float $seconds): RequestLimits
    {
        return new RequestLimits(idleTimeout: $seconds, maxDuration: $seconds, tickInterval: 0.25);
    }

    /** A thrown request as its Error, logged by class: a transport failure at the maximum duration is a timeout. */
    private function failure(\Throwable $e, float $started, AiRequest $request, int $userId): Error
    {
        $error = EventMapper::exception($e, ($this->clock)() - $started >= $request->maxDuration());
        $this->logFailure($error->code, $userId, $request->feature, self::shortClass($e));
        return $error;
    }

    private function logDroppedTool(int $userId, string $feature, int $index): void
    {
        $this->logger->info(sprintf('[AiConnector] AI stream dropped a tool call with an invalid name or id: user %d, feature %s, index %d',
            $userId, self::safeFeature($feature), $index));
    }

    private function logFailure(string $code, int $userId, string $feature, string $detail): void
    {
        $this->logger->info(sprintf('[AiConnector] AI stream failed (%s): user %d, feature %s, %s',
            $code, $userId, self::safeFeature($feature), $detail));
    }

    private static function safeFeature(string $feature): string
    {
        return mb_substr(preg_replace('/[^a-z0-9._-]/i', '', $feature), 0, 64);
    }

    /** An exception's class for the log: its last name segment, cut at the @ of an anonymous class. */
    private static function shortClass(\Throwable $e): string
    {
        $class = strstr(get_class($e), '@', true) ?: get_class($e);
        $short = substr(strrchr('\\' . $class, '\\'), 1);
        return preg_replace('/[^A-Za-z0-9_]/', '', $short);
    }

    /**
     * The one refusal path: a fixed sentence for the person and one sanitized log line (never the
     * raw feature, a profile id, a URL, a key or an exception message; $detail is a class name).
     */
    private function refuse(string $code, int $userId, string $feature, ?string $detail = null, ?string $message = null, bool $log = true): Refused
    {
        if ($log) {
            $this->logger->info(sprintf('[AiConnector] AI stream refused (%s): user %d, feature %s%s',
                $code, $userId, self::safeFeature($feature), $detail === null ? '' : ', ' . $detail));
        }

        return new Refused($code, $message ?? match ($code) {
            'not_ready' => t('AI is not set up.'),
            'not_signed_in' => t('Sign in to use AI features.'),
            'cancelled' => t('The AI request was cancelled.'),
            default => t('AI is unavailable right now. Try again later.'),
        });
    }
}
