<?php

namespace Kanboard\Plugin\AiConnector\Stream;

use Kanboard\Core\Base;
use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Stream\Event\Refused;
use Kanboard\Plugin\AiConnector\Stream\Event\Started;
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
            yield $this->refuse('setup_failed', $userId, $feature, get_class($e));
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
            yield $this->refuse($reservation->outcome, $userId, $feature, null, $reservation->message);
            return;
        }

        try {
            $profile = $registry->findProfile($profileId) ?? ['provider' => '', 'model' => ''];
            $model = $profile['model'] !== '' ? $profile['model'] : (ProviderRegistry::DEFAULT_MODELS[$profile['provider']] ?? '');
            yield new Started($model, $provider instanceof \CarmeloSantana\PHPAgents\Provider\AbstractProvider);
        } finally {
            $quota->closeAttempt((int) $reservation->attemptId, 'finished');
        }
    }

    /**
     * The one refusal path: a fixed sentence for the person and one sanitized log line (never the
     * raw feature, a profile id, a URL, a key or an exception message; $detail is a class name).
     */
    private function refuse(string $code, int $userId, string $feature, ?string $detail = null, ?string $message = null): Refused
    {
        $this->logger->info(sprintf('[AiConnector] AI stream refused (%s): user %d, feature %s%s',
            $code, $userId, mb_substr(preg_replace('/[^a-z0-9._-]/i', '', $feature), 0, 64),
            $detail === null ? '' : ', ' . preg_replace('/[^A-Za-z0-9_\\\\]/', '', $detail)));

        return new Refused($code, $message ?? match ($code) {
            'not_ready' => t('AI is not set up.'),
            'not_signed_in' => t('Sign in to use AI features.'),
            'cancelled' => t('The AI request was cancelled.'),
            default => t('AI is unavailable right now. Try again later.'),
        });
    }
}
