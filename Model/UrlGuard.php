<?php

namespace Kanboard\Plugin\AiConnector\Model;

/**
 * SSRF guard for provider base URLs.
 *
 *  - Host allow-list (admin setting). Empty = any host. Entries are exact
 *    hosts / IPs, or "*.example.com" for subdomains of example.com.
 *  - After DNS resolution, every address must be public. Private networks
 *    (RFC 1918, loopback, CGNAT, IPv6 ULA) need the explicit LAN opt-in, for
 *    a local Ollama and the like. Link-local, cloud metadata, multicast and
 *    reserved addresses are refused even with the opt-in.
 *  - httpClient() returns a Symfony client pinned to the checked address
 *    (the `resolve` option) with redirects off, so a DNS re-bind or a 30x
 *    cannot move the request somewhere the check did not see.
 *
 * The allow-list and LAN opt-in are independent: both must pass.
 *
 * @package Kanboard\Plugin\AiConnector\Model
 */
class UrlGuard
{
    public const ALLOWED_HOSTS_KEY = 'aiconnector_allowed_hosts';
    public const ALLOW_PRIVATE_KEY = 'aiconnector_allow_private';

    /** Private networks: allowed only with the LAN opt-in. */
    private const PRIVATE_CIDRS = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '100.64.0.0/10',
        '198.18.0.0/15', '::1/128', 'fc00::/7',
    ];

    /** Always refused: link-local, cloud metadata, unspecified, multicast, reserved. */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8', '169.254.0.0/16', '100.100.100.200/32', '192.0.0.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', 'fe80::/10', 'fd00:ec2::254/128', 'ff00::/8', '2001::/32', '::/96',
    ];

    /** @var callable(string): string[] | null  Test seam replacing DNS. */
    private static $resolver = null;

    /** @var string[] */
    private array $allowedHosts;

    /** @param string[] $allowedHosts */
    public function __construct(array $allowedHosts = [], private bool $allowPrivate = false)
    {
        $this->allowedHosts = self::parseHostList(implode("\n", $allowedHosts));
    }

    public static function fromConfig(object $configModel): self
    {
        return new self(
            self::parseHostList((string) $configModel->get(self::ALLOWED_HOSTS_KEY, '')),
            (string) $configModel->get(self::ALLOW_PRIVATE_KEY, '') === '1'
        );
    }

    /** Replace DNS resolution in tests; null restores the real resolver. */
    public static function setResolverForTesting(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Check $url and return the host and the address to pin it to.
     *
     * @return array{host:string, ip:string}
     * @throws UnsafeUrlException
     */
    public function check(string $url): array
    {
        if (! ProviderRegistry::isValidBaseUrl($url) || $url === '') {
            throw new UnsafeUrlException(UnsafeUrlException::INVALID_URL);
        }
        $host = self::normaliseHost((string) parse_url($url, PHP_URL_HOST));
        if (self::isNumericShorthand($host)) {
            throw new UnsafeUrlException(UnsafeUrlException::INVALID_URL);
        }

        if ($this->allowedHosts !== [] && ! $this->hostAllowed($host)) {
            throw new UnsafeUrlException(UnsafeUrlException::HOST_NOT_ALLOWED, $host);
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            throw new UnsafeUrlException(UnsafeUrlException::UNRESOLVABLE, $host);
        }
        foreach ($ips as $ip) {
            $class = self::classify($ip);
            if ($class === 'blocked') {
                throw new UnsafeUrlException(UnsafeUrlException::BLOCKED_ADDRESS, $host);
            }
            if ($class === 'private' && ! $this->allowPrivate) {
                throw new UnsafeUrlException(UnsafeUrlException::PRIVATE_NETWORK, $host);
            }
        }

        return ['host' => $host, 'ip' => $ips[0]];
    }

    /**
     * A Symfony HTTP client for requests to $url: checked, pinned to the
     * checked address, redirects off.
     *
     * @param array<string, mixed> $options extra default options (e.g. timeout)
     * @throws UnsafeUrlException
     */
    public function httpClient(string $url, array $options = []): \Symfony\Contracts\HttpClient\HttpClientInterface
    {
        ['host' => $host, 'ip' => $ip] = $this->check($url);
        return \Symfony\Component\HttpClient\HttpClient::create([
            'resolve'       => [$host => $ip],
            'max_redirects' => 0,
        ] + $options);
    }

    /**
     * Split a textarea / comma list into normalised, de-duplicated entries.
     *
     * @return string[]
     */
    public static function parseHostList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $entry) {
            $entry = self::normaliseHost($entry);
            if ($entry !== '' && ! in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** An allow-list entry: hostname, IP literal, or "*.domain". */
    public static function isValidHostEntry(string $entry): bool
    {
        $entry = self::normaliseHost($entry);
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (str_starts_with($entry, '*.')) {
            $entry = substr($entry, 2);
        }
        return $entry !== ''
            && ! str_contains($entry, '*')
            && filter_var($entry, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    // ── internals ────────────────────────────────────────────────────────────────

    private static function normaliseHost(string $host): string
    {
        return rtrim(strtolower(trim(trim($host), '[]')), '.');
    }

    /** 2130706433, 0x7f.1, 0177.0.0.1, 127.1 — numeric but not a canonical IP. */
    private static function isNumericShorthand(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (! preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $label)) {
                return false;
            }
        }
        return true;
    }

    private function hostAllowed(string $host): bool
    {
        foreach ($this->allowedHosts as $entry) {
            if ($entry === $host) {
                return true;
            }
            if (str_starts_with($entry, '*.') && str_ends_with($host, substr($entry, 1))) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if (self::$resolver !== null) {
            return array_values((self::$resolver)($host));
        }
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    /** 'public' | 'private' | 'blocked' (anything unparseable is blocked). */
    private static function classify(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'blocked';
        }
        // IPv6 forms that carry an IPv4 address: judge the embedded one.
        if (strlen($bin) === 16) {
            $embedded = match (true) {
                str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") => substr($bin, 12, 4), // ::ffff:a.b.c.d
                str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8)) => substr($bin, 12, 4), // 64:ff9b::/96 NAT64
                str_starts_with($bin, "\x20\x02") => substr($bin, 2, 4), // 2002::/16 6to4
                default => null,
            };
            if ($embedded !== null) {
                return self::classify((string) inet_ntop($embedded));
            }
        }
        if ($bin === inet_pton('::1')) {
            return 'private'; // loopback; the rest of ::/96 is deprecated and blocked
        }
        if (self::inAny($bin, self::BLOCKED_CIDRS)) {
            return 'blocked';
        }
        return self::inAny($bin, self::PRIVATE_CIDRS) ? 'private' : 'public';
    }

    /** @param string[] $cidrs */
    private static function inAny(string $bin, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = inet_pton($net);
            if (strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits  = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rem = $bits % 8;
            if ($rem === 0) {
                return true;
            }
            $mask = (0xff << (8 - $rem)) & 0xff;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
