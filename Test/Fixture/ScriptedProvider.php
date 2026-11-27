<?php

namespace Kanboard\Plugin\AiConnector\Test\Fixture;

use CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use CarmeloSantana\PHPAgents\Provider\Response;

/**
 * A php-agents provider for AiStream tests: streamEvents() yields a scripted list of php-agents
 * events, or throws the scripted exception once they run out. Every call is recorded in $calls,
 * so a test can assert that no provider request was made. It never touches the network.
 */
final class ScriptedProvider extends AbstractProvider
{
    /** @var list<array{method: string, args: array}> */
    public array $calls = [];

    /** @param list<object> $events */
    public function __construct(private array $events = [], private ?\Throwable $throw = null, string $model = 'scripted')
    {
        parent::__construct($model, 'http://scripted.invalid');
    }

    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->calls[] = ['method' => 'streamEvents', 'args' => [$messages, $tools, $options]];
        return $this->play();
    }

    private function play(): \Generator
    {
        foreach ($this->events as $event) {
            yield $event;
        }
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $this->calls[] = ['method' => 'chat', 'args' => [$messages, $tools, $options]];
        throw new \LogicException('ScriptedProvider::chat() is not scripted');
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $this->calls[] = ['method' => 'structured', 'args' => [$messages, $schema, $options]];
        throw new \LogicException('ScriptedProvider::structured() is not scripted');
    }

    public function models(): array
    {
        $this->calls[] = ['method' => 'models', 'args' => []];
        return [];
    }

    public function isAvailable(): bool
    {
        $this->calls[] = ['method' => 'isAvailable', 'args' => []];
        return true;
    }

    protected function formatTools(array $tools): array
    {
        return [];
    }

    protected function formatMessages(array $messages): array
    {
        return [];
    }

    protected function parseResponse(array $data): Response
    {
        throw new \LogicException('ScriptedProvider::parseResponse() is not scripted');
    }
}
