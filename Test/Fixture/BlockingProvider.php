<?php

namespace Kanboard\Plugin\AiConnector\Test\Fixture;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Contract\UsageReporting;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\UsageReport;

/**
 * A php-agents provider that is not an AbstractProvider (as CliProvider or an injected host
 * provider would be): chat() and structured() return or throw what the test scripted, and
 * streamEvents() is never expected. Every call is recorded in $calls, shared with the copy
 * withUsageListener() returns; when $report is scripted, the attached listener receives it
 * during the call, as php-agents providers report usage.
 */
final class BlockingProvider implements ProviderInterface, UsageReporting
{
    /** @var list<array{method: string, args: array, listener?: bool}> */
    public array $calls = [];

    public ?UsageReport $report = null;

    private ?UsageListener $listener = null;

    /** @var array{calls: array} a reference to $calls, kept alive across clones */
    private array $shared;

    public function __construct(
        private Response|\Throwable|null $chat = null,
        private mixed $structured = null,
        private string $model = 'blocking',
    ) {
        $this->shared = ['calls' => &$this->calls];
    }

    public function withUsageListener(UsageListener $listener): static
    {
        $clone = clone $this;
        $clone->listener = $listener;
        return $clone;
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $this->calls[] = ['method' => 'chat', 'args' => [$messages, $tools, $options], 'listener' => $this->listener !== null];
        if ($this->report !== null) {
            $this->listener?->onUsage($this->report);
        }
        if ($this->chat instanceof \Throwable) {
            throw $this->chat;
        }
        return $this->chat ?? throw new \LogicException('BlockingProvider::chat() is not scripted');
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $this->calls[] = ['method' => 'structured', 'args' => [$messages, $schema, $options], 'listener' => $this->listener !== null];
        if ($this->report !== null) {
            $this->listener?->onUsage($this->report);
        }
        if ($this->structured instanceof \Throwable) {
            throw $this->structured;
        }
        return $this->structured;
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->calls[] = ['method' => 'stream', 'args' => [$messages, $tools, $options]];
        throw new \LogicException('BlockingProvider::stream() is not scripted');
    }

    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->calls[] = ['method' => 'streamEvents', 'args' => [$messages, $tools, $options]];
        throw new \LogicException('BlockingProvider::streamEvents() is not scripted');
    }

    public function models(): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;
        return $clone;
    }
}
