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
use Kanboard\Plugin\AiConnector\Stream\Event\Terminal;
use Kanboard\Plugin\AiConnector\Stream\Event\TextDelta;
use Kanboard\Plugin\AiConnector\Stream\Internal\BlockingCall;
use Kanboard\Plugin\AiConnector\Stream\Internal\EventMapper;
use Kanboard\Plugin\AiConnector\Stream\Internal\MessageMapper;
use Kanboard\Plugin\AiConnector\Stream\Internal\StructuredDecoder;
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

    /**
     * The signed-in user is read here, when events() is called, not when the generator first
     * runs: a generator built for one person and iterated under another session still acts for
     * the person who asked.
     *
     * @return \Generator<int, object> events, ending with exactly one terminal event when drained
     */
    public function events(AiRequest $request): \Generator
    {
        return $this->run($request, (int) $this->userSession->getId());
    }

    /** @return \Generator<int, object> */
    private function run(AiRequest $request, int $userId): \Generator
    {
        $feature = $request->feature;
        try {
            $admitted = $this->admit($request, $userId);
        } catch (\LogicException $e) {
            // A programmer error (AiQuota called inside a transaction), not an outage.
            throw $e;
        } catch (\Throwable $e) {
            // A database or configuration failure before any provider call: one terminal event,
            // never an exception through the event API. Nothing was charged (reserve() rolls back).
            $admitted = $this->refuse('unavailable', $userId, $feature, self::shortClass($e));
        }
        if ($admitted instanceof Refused) {
            yield $admitted;
            return;
        }
        [$provider, $model, $quota, $attemptId, $deadline] = $admitted;

        $closed = false;
        try {
            foreach ($this->stream($provider, $request, $model, $userId, $deadline) as $event) {
                if ($event instanceof Terminal) {
                    // Closed before the host sees the terminal: a host that keeps the generator
                    // after it holds no concurrency slot.
                    $closed = true;
                    $this->closeAttempt($quota, $userId, $attemptId, $event instanceof Finish ? 'finished' : 'failed', $feature);
                }
                yield $event;
            }
        } finally {
            // Abandoned or thrown before a terminal: the attempt closes exactly once, here.
            if (! $closed) {
                $this->closeAttempt($quota, $userId, $attemptId, 'failed', $feature);
            }
        }
    }

    /**
     * The guard rails, in order, up to a confirmed reservation: a Refused, or what the stream
     * needs. Anything here may throw; run() turns that into Refused(unavailable).
     *
     * @return Refused|array{0: object, 1: string, 2: AiQuota, 3: int, 4: float}
     */
    private function admit(AiRequest $request, int $userId): Refused|array
    {
        $registry = $this->registry ?? new ProviderRegistry($this->container);
        $feature = $request->feature;

        if (! $registry->isReady()) {
            return $this->refuse('not_ready', 0, $feature);
        }
        if ($userId <= 0) {
            return $this->refuse('not_signed_in', 0, $feature);
        }
        if (! array_key_exists($feature, $registry->features())) {
            return $this->refuse('unknown_feature', $userId, $feature);
        }

        $profileId = $registry->resolve($feature, $request->projectId, $request->pick);
        if ($profileId === '') {
            return $this->refuse('no_profile', $userId, $feature);
        }

        try {
            $provider = $registry->buildProvider($profileId);
        } catch (\Throwable $e) {
            return $this->refuse('setup_failed', $userId, $feature, self::shortClass($e));
        }

        if ($request->cancel?->isCancelled()) {
            return $this->refuse('cancelled', $userId, $feature);
        }

        // Resolved before the reservation: nothing after reserve() may throw outside stream()'s catch.
        $profile = $registry->findProfile($profileId) ?? ['provider' => '', 'model' => ''];
        $model = $profile['model'] !== '' ? $profile['model'] : (ProviderRegistry::DEFAULT_MODELS[$profile['provider']] ?? '');

        $input = array_column($request->messages, 'content');
        if ($request->schema !== null) {
            $input[] = $request->schema;
        }
        $quota = $this->quota ?? new AiQuota($this->container);
        // The deadline counts from the reservation, before its lease (maxDuration + 60 s) starts:
        // time the consumer spends between events is spent from it too.
        $deadline = ($this->clock)() + $request->maxDuration();
        $reservation = $quota->reserve($userId, $feature, $input, $request->attemptKey, $request->maxDuration());
        if (! $reservation->ok()) {
            // AiQuota already logged its refusal: one line per refusal.
            return $this->refuse($reservation->outcome, $userId, $feature, null, $reservation->message, log: false);
        }
        return [$provider, $model, $quota, (int) $reservation->attemptId, $deadline];
    }

    /** Ends the attempt; a failure to record that is logged and never replaces the outcome. */
    private function closeAttempt(AiQuota $quota, int $userId, int $attemptId, string $status, string $feature): void
    {
        try {
            $quota->closeAttempt($userId, $attemptId, $status);
        } catch (\Throwable $e) {
            $this->logger->info(sprintf('[AiConnector] AI stream could not close its attempt: user %d, feature %s, %s',
                $userId, self::safeFeature($feature), self::shortClass($e)));
        }
    }

    /**
     * Started, the mapped provider events and Progress heartbeats, then exactly one terminal
     * event (Finish or Error), after which nothing is yielded. The token and the deadline are
     * checked on every upstream event, ticks included; a cancel or the deadline before the
     * provider's Finish stops reading (Finish(cancelled) or Error(timeout)), which abandons the
     * php-agents generator and so cancels its HTTP request. Reading stops at the provider's
     * Finish too: a cancel or the deadline after it changes nothing.
     *
     * A request with a schema takes the structured path (structured()). A provider that is not
     * an AbstractProvider gets one blocking call instead. A streamed
     * request rejected as a request-shape error before any output gets one blocking call on the
     * same attempt and charge (BlockingCall, no second Started).
     *
     * @return \Generator<int, object>
     */
    private function stream(object $provider, AiRequest $request, string $model, int $userId, float $deadline): \Generator
    {
        $started = ($this->clock)();
        $messages = MessageMapper::toPhpAgents($request->messages);
        $blocking = new BlockingCall(
            $model,
            onException: fn (\Throwable $e): Error => $this->failure($e, $deadline, $request, $userId),
            onFinishError: fn (string $detail) => $this->logFailure('provider_error', $userId, $request->feature, $detail),
            onDroppedTool: fn (int $index) => $this->logDroppedTool($userId, $request->feature, $index),
            prepare: fn (object $provider, bool $structured): object => $this->blockingProvider($provider, $structured, $deadline, $request, $userId),
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

        $remaining = $this->remaining($deadline);
        if ($remaining <= 0.0) {
            yield $this->deadlinePassed($request, $userId);
            return;
        }
        $provider = $provider->withRequestLimits(new RequestLimits(
            idleTimeout: min($request->idleTimeout(), $remaining),
            maxDuration: $remaining,
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
                // Enforced here, not only by the transport's max_duration.
                if (($terminal = $this->halted($request, $model, $userId, $deadline)) !== null) {
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
                    // The host decides when this generator resumes: check again before anything else.
                    if (($terminal = $this->halted($request, $model, $userId, $deadline)) !== null) {
                        break;
                    }
                }

                $now = ($this->clock)();
                if ($now - $lastProgress >= 1.0) {
                    $lastProgress = $now;
                    yield new Progress((int) round(($now - $started) * 1000), $receivedChars);
                    if (($terminal = $this->halted($request, $model, $userId, $deadline)) !== null) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($request->cancel?->isCancelled()) {
                // Cancelled before a Finish arrived: whatever the request then threw, it was cancelled.
                $terminal = new Finish('cancelled', $model, null, null);
            } else {
                // Rejected before any output: a protocol fallback, not a retry.
                $rejected = $output ? null : EventMapper::rejectedStatus($e);
                $terminal = $rejected === null ? $this->failure($e, $deadline, $request, $userId) : null;
            }
        }

        if ($rejected !== null) {
            $this->logger->info(sprintf('[AiConnector] AI stream: streaming rejected (HTTP %d), blocking fallback: user %d, feature %s',
                $rejected, $userId, self::safeFeature($request->feature)));
            // Limited to the time left by blockingProvider(); none left: Error(timeout), no call.
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
     * blocking structured() call (BlockingCall), limited by blockingProvider() right before it.
     *
     * @return \Generator<int, object>
     */
    private function structured(object $provider, BlockingCall $blocking, array $messages, AiRequest $request): \Generator
    {
        yield from $blocking->structured($provider, $messages, (string) $request->schema, $request->cancel);
    }

    /**
     * BlockingCall's last step before a provider call: past the deadline, the Error that replaces
     * the call; else the provider limited to the time left. A blocking answer sends nothing until
     * it is done, so idle = max = the time left. A structured call's body is capped at
     * StructuredDecoder::MAX_RESPONSE_BYTES, so an oversized document fails in transport before
     * it is decoded. A provider that is not an AbstractProvider takes no limits.
     */
    private function blockingProvider(object $provider, bool $structured, float $deadline, AiRequest $request, int $userId): object
    {
        $remaining = $this->remaining($deadline);
        if ($remaining <= 0.0) {
            return $this->deadlinePassed($request, $userId);
        }
        return $provider instanceof AbstractProvider ? $provider->withRequestLimits(self::blockingLimits($remaining, $structured)) : $provider;
    }

    /** Finish(cancelled) once cancelled, Error(timeout) past the deadline, else null: keep reading. */
    private function halted(AiRequest $request, string $model, int $userId, float $deadline): Finish|Error|null
    {
        if ($request->cancel?->isCancelled()) {
            return new Finish('cancelled', $model, null, null);
        }
        return $this->remaining($deadline) <= 0.0 ? $this->deadlinePassed($request, $userId) : null;
    }

    /** Seconds left before the deadline; zero or less once it has passed. */
    private function remaining(float $deadline): float
    {
        return $deadline - ($this->clock)();
    }

    /** The deadline passed before a provider call or while reading one: Error(timeout), logged. */
    private function deadlinePassed(AiRequest $request, int $userId): Error
    {
        $this->logFailure('timeout', $userId, $request->feature, 'deadline passed');
        return EventMapper::error('timeout');
    }

    /** Limits for one blocking call: no bytes arrive until it is done, so idle = max = $seconds. */
    private static function blockingLimits(float $seconds, bool $structured): RequestLimits
    {
        return $structured
            ? new RequestLimits(idleTimeout: $seconds, maxDuration: $seconds, maxResponseBytes: StructuredDecoder::MAX_RESPONSE_BYTES, tickInterval: 0.25)
            : new RequestLimits(idleTimeout: $seconds, maxDuration: $seconds, tickInterval: 0.25);
    }

    /** A thrown request as its Error, logged by class: a transport failure at the deadline is a timeout. */
    private function failure(\Throwable $e, float $deadline, AiRequest $request, int $userId): Error
    {
        $error = EventMapper::exception($e, $this->remaining($deadline) <= 0.0);
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
