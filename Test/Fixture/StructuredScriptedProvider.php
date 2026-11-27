<?php

namespace Kanboard\Plugin\AiConnector\Test\Fixture;

use CarmeloSantana\PHPAgents\Contract\StructuredStreaming;

/**
 * A ScriptedProvider that also streams structured output (php-agents 0.21's StructuredStreaming):
 * structuredEvents() plays the same script streamEvents() would, and is recorded in $calls.
 */
final class StructuredScriptedProvider extends ScriptedProvider implements StructuredStreaming
{
    public function structuredEvents(array $messages, string $schema, array $options = []): iterable
    {
        $this->calls[] = ['method' => 'structuredEvents', 'args' => [$messages, $schema, $options], 'limits' => $this->requestLimits()];
        return $this->play();
    }
}
