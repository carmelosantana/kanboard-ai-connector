<?php

namespace Kanboard\Plugin\AiConnector\Stream\Internal;

use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;

/** The suite's role/content message arrays as php-agents messages. */
final class MessageMapper
{
    /**
     * [['role' => .., 'content' => ..], ...] to php-agents message objects. Unknown roles fall
     * back to UserMessage (defensive).
     *
     * @param array<int, array{role:string, content:string}|string> $messages
     * @return array<int, object>
     */
    public static function toPhpAgents(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role    = is_array($m) ? (string) ($m['role'] ?? 'user') : 'user';
            $content = is_array($m) ? (string) ($m['content'] ?? '') : (string) $m;
            $out[] = match ($role) {
                'system'    => new SystemMessage($content),
                'assistant' => new AssistantMessage($content),
                default     => new UserMessage($content),
            };
        }
        return $out;
    }
}
