<?php

namespace Kanboard\Plugin\AiConnector\Model;

/**
 * A provider URL the SSRF guard refused. The message names the host and the
 * reason only — never the path, query string or any API key — so it is safe
 * to show an admin.
 *
 * @package Kanboard\Plugin\AiConnector\Model
 */
class UnsafeUrlException extends \RuntimeException
{
    public const INVALID_URL      = 'invalid_url';
    public const HOST_NOT_ALLOWED = 'host_not_allowed';
    public const UNRESOLVABLE     = 'unresolvable';
    public const PRIVATE_NETWORK  = 'private_network';
    public const BLOCKED_ADDRESS  = 'blocked_address';

    public function __construct(public readonly string $reason, string $host = '')
    {
        $shown = $host !== '' ? '"' . $host . '"' : 'the provider URL';
        parent::__construct(match ($reason) {
            self::INVALID_URL      => 'The provider URL must be an http:// or https:// address with a host and no credentials.',
            self::HOST_NOT_ALLOWED => sprintf('%s is not on the AI Connector host allow-list.', $shown),
            self::UNRESOLVABLE     => sprintf('%s could not be resolved.', $shown),
            self::PRIVATE_NETWORK  => sprintf('%s is on a private network (LAN, loopback). Turn on "Allow providers on private networks" to use it.', $shown),
            default                => sprintf('%s resolves to a link-local, metadata or reserved address, which is always blocked.', $shown),
        });
    }
}
