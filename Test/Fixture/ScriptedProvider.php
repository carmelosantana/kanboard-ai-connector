<?php

namespace Kanboard\Plugin\AiConnector\Test\Fixture;

use CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Response;

/**
 * A php-agents provider for AiStream tests: streamEvents() yields a scripted list of php-agents
 * events, or throws the scripted exception once they run out. A \Closure in the script is run
 * instead of yielded (a side effect between events, such as a cancel). Every call is recorded in
 * $calls, so a test can assert that no provider request was made. It never touches the network.
 *
 * $calls and $ended are shared with clones (withRequestLimits() returns one), so the test's
 * instance sees what the streamed copy did.
 */
class ScriptedProvider extends AbstractProvider
{
    /** @var list<array{method: string, args: array, limits?: RequestLimits}> */
    public array $calls = [];

    /** How the last stream ended: null (not started or still open), 'completed', 'threw' or 'abandoned'. */
    public ?string $ended = null;

    /** What chat() returns or throws; set before the stream starts, so the limited copy has it. */
    public Response|\Throwable|null $chatAnswer = null;

    /** What structured() returns (array, Response, …) or throws; null means not scripted. */
    public mixed $structuredAnswer = null;

    /** @var array{calls: array, ended: ?string} references to $calls and $ended */
    private array $shared;

    /** @param list<object> $events */
    public function __construct(private array $events = [], private ?\Throwable $throw = null, string $model = 'scripted')
    {
        parent::__construct($model, 'http://scripted.invalid');
        // A clone keeps a property reference only while something else holds it too (PHP unwraps
        // a reference with one holder), so $shared keeps both alive: every copy records into the
        // same slots.
        $this->shared = ['calls' => &$this->calls, 'ended' => &$this->ended];
    }

    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->calls[] = ['method' => 'streamEvents', 'args' => [$messages, $tools, $options], 'limits' => $this->requestLimits()];
        return $this->play();
    }

    protected function play(): \Generator
    {
        $how = 'abandoned';
        try {
            foreach ($this->events as $event) {
                if ($event instanceof \Closure) {
                    $event();
                    continue;
                }
                yield $event;
            }
            if ($this->throw !== null) {
                $how = 'threw';
                throw $this->throw;
            }
            $how = 'completed';
        } finally {
            $this->ended = $how;
        }
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $this->calls[] = ['method' => 'chat', 'args' => [$messages, $tools, $options], 'limits' => $this->requestLimits()];
        if ($this->chatAnswer instanceof \Throwable) {
            throw $this->chatAnswer;
        }
        return $this->chatAnswer ?? throw new \LogicException('ScriptedProvider::chat() is not scripted');
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $this->calls[] = ['method' => 'structured', 'args' => [$messages, $schema, $options], 'limits' => $this->requestLimits()];
        if ($this->structuredAnswer instanceof \Throwable) {
            throw $this->structuredAnswer;
        }
        return $this->structuredAnswer ?? throw new \LogicException('ScriptedProvider::structured() is not scripted');
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
