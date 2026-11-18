<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\UnsafeUrlException;
use Kanboard\Plugin\AiConnector\Model\UrlGuard;
use KanboardTests\units\Base;

/**
 * SSRF guard for provider base URLs: host allow-list, private / loopback /
 * link-local blocking after DNS resolution, explicit LAN opt-in. No network:
 * DNS goes through the resolver seam (or the numeric-literal fast path).
 */
class UrlGuardTest extends Base
{
    protected function tearDown(): void
    {
        UrlGuard::setResolverForTesting(null);
        parent::tearDown();
    }

    /** @param array<string, string[]> $map */
    private function dns(array $map): void
    {
        UrlGuard::setResolverForTesting(fn (string $host): array => $map[$host] ?? []);
    }

    private function assertRefused(UrlGuard $guard, string $url, string $reason): void
    {
        try {
            $guard->check($url);
            $this->fail("expected $url to be refused ($reason)");
        } catch (UnsafeUrlException $e) {
            $this->assertSame($reason, $e->reason, $url);
        }
    }

    public function testPublicHostIsAllowedAndPinnedToItsAddress(): void
    {
        $this->dns(['api.openai.com' => ['162.159.140.245']]);
        $this->assertSame(
            ['host' => 'api.openai.com', 'ip' => '162.159.140.245'],
            (new UrlGuard())->check('https://api.openai.com/v1')
        );
    }

    /** @dataProvider privateAddresses */
    public function testPrivateAddressesAreRefusedByDefault(string $ip): void
    {
        $this->dns(['internal.example' => [$ip]]);
        $this->assertRefused(new UrlGuard(), 'http://internal.example/v1', UnsafeUrlException::PRIVATE_NETWORK);
    }

    /** @dataProvider privateAddresses */
    public function testPrivateAddressesAreAllowedWithTheLanOptIn(string $ip): void
    {
        $this->dns(['internal.example' => [$ip]]);
        $this->assertSame($ip, (new UrlGuard([], true))->check('http://internal.example/v1')['ip']);
    }

    public static function privateAddresses(): array
    {
        return [
            'loopback v4'       => ['127.0.0.1'],
            'loopback v4 range' => ['127.1.2.3'],
            '10/8'              => ['10.0.0.5'],
            '172.16/12'         => ['172.20.1.1'],
            '192.168/16'        => ['192.168.1.20'],
            'CGNAT/Tailscale'   => ['100.64.0.10'],
            'loopback v6'       => ['::1'],
            'ULA v6'            => ['fd12:3456::1'],
            'v4-mapped private' => ['::ffff:192.168.1.20'],
        ];
    }

    /** @dataProvider alwaysBlockedAddresses */
    public function testMetadataAndLinkLocalStayBlockedEvenWithTheLanOptIn(string $ip): void
    {
        $this->dns(['evil.example' => [$ip]]);
        $this->assertRefused(new UrlGuard(), 'http://evil.example/', UnsafeUrlException::BLOCKED_ADDRESS);
        $this->assertRefused(new UrlGuard([], true), 'http://evil.example/', UnsafeUrlException::BLOCKED_ADDRESS);
    }

    public static function alwaysBlockedAddresses(): array
    {
        return [
            'cloud metadata v4'      => ['169.254.169.254'],
            'link-local v4'          => ['169.254.10.1'],
            'this network'           => ['0.0.0.0'],
            'multicast v4'           => ['224.0.0.1'],
            'broadcast'              => ['255.255.255.255'],
            'link-local v6'          => ['fe80::1'],
            'AWS metadata v6'        => ['fd00:ec2::254'],
            'Alibaba metadata'       => ['100.100.100.200'],
            'unspecified v6'         => ['::'],
            'multicast v6'           => ['ff02::1'],
            'v4-mapped metadata'     => ['::ffff:169.254.169.254'],
            'NAT64 metadata'         => ['64:ff9b::a9fe:a9fe'],
            '6to4 metadata'          => ['2002:a9fe:a9fe::1'],
        ];
    }

    public function testEveryResolvedAddressMustPass(): void
    {
        $this->dns(['mixed.example' => ['93.184.216.34', '10.0.0.1']]);
        $this->assertRefused(new UrlGuard(), 'https://mixed.example/', UnsafeUrlException::PRIVATE_NETWORK);
    }

    public function testUnresolvableHostIsRefused(): void
    {
        $this->dns([]);
        $this->assertRefused(new UrlGuard(), 'https://nowhere.invalid/', UnsafeUrlException::UNRESOLVABLE);
    }

    public function testIpLiteralsSkipDns(): void
    {
        $this->dns([]);
        $this->assertSame('8.8.8.8', (new UrlGuard())->check('http://8.8.8.8/v1')['ip']);
        $this->assertSame('::1', (new UrlGuard([], true))->check('http://[::1]:11434/v1')['ip']);
        $this->assertRefused(new UrlGuard(), 'http://[::1]:11434/v1', UnsafeUrlException::PRIVATE_NETWORK);
    }

    /** Numeric shorthand (2130706433, 0x7f.1, 0177.0.0.1) is refused outright: resolvers disagree on it. */
    public function testNumericShorthandHostsAreRefused(): void
    {
        $this->dns(['cafe.be' => ['93.184.216.34']]);
        foreach (['http://2130706433/', 'http://0x7f.1/', 'http://0177.0.0.1/', 'http://127.1/'] as $url) {
            $this->assertRefused(new UrlGuard([], true), $url, UnsafeUrlException::INVALID_URL);
        }
        $this->assertSame('cafe.be', (new UrlGuard())->check('https://cafe.be/')['host']);
    }

    public function testLocalhostIsRefusedByDefault(): void
    {
        $this->assertRefused(new UrlGuard(), 'http://localhost:11434/v1', UnsafeUrlException::PRIVATE_NETWORK);
    }

    public function testMalformedUrlsAreRefused(): void
    {
        $this->assertRefused(new UrlGuard(), 'file:///etc/passwd', UnsafeUrlException::INVALID_URL);
        $this->assertRefused(new UrlGuard(), 'https://user:pass@example.com/', UnsafeUrlException::INVALID_URL);
        $this->assertRefused(new UrlGuard(), '', UnsafeUrlException::INVALID_URL);
    }

    public function testAllowListRestrictsHosts(): void
    {
        $this->dns([
            'api.openai.com'   => ['162.159.140.245'],
            'gw.example.com'   => ['93.184.216.34'],
            'a.b.example.com'  => ['93.184.216.34'],
            'example.com'      => ['93.184.216.34'],
            'attacker.net'     => ['93.184.216.35'],
        ]);
        $guard = new UrlGuard(['api.openai.com', '*.example.com']);

        $this->assertSame('api.openai.com', $guard->check('https://API.openai.com./v1')['host']);
        $this->assertSame('gw.example.com', $guard->check('https://gw.example.com/v1')['host']);
        $this->assertSame('a.b.example.com', $guard->check('https://a.b.example.com/v1')['host']);
        $this->assertRefused($guard, 'https://example.com/v1', UnsafeUrlException::HOST_NOT_ALLOWED);
        $this->assertRefused($guard, 'https://attacker.net/v1', UnsafeUrlException::HOST_NOT_ALLOWED);
        $this->assertRefused($guard, 'https://api.openai.com.attacker.net/v1', UnsafeUrlException::HOST_NOT_ALLOWED);
    }

    public function testAllowListDoesNotBypassThePrivateNetworkBlock(): void
    {
        $this->dns(['ollama.lan' => ['192.168.1.20']]);
        $this->assertRefused(new UrlGuard(['ollama.lan']), 'http://ollama.lan:11434/v1', UnsafeUrlException::PRIVATE_NETWORK);
        $this->assertSame('192.168.1.20', (new UrlGuard(['ollama.lan'], true))->check('http://ollama.lan:11434/v1')['ip']);
    }

    public function testAllowListMatchesIpLiterals(): void
    {
        $guard = new UrlGuard(['192.168.1.20', '::1'], true);
        $this->assertSame('192.168.1.20', $guard->check('http://192.168.1.20:11434/v1')['ip']);
        $this->assertSame('::1', $guard->check('http://[::1]:11434/v1')['ip']);
        $this->assertRefused($guard, 'http://192.168.1.21:11434/v1', UnsafeUrlException::HOST_NOT_ALLOWED);
    }

    public function testParseHostList(): void
    {
        $this->assertSame(
            ['api.openai.com', '*.example.com', '192.168.1.20', '::1'],
            UrlGuard::parseHostList("API.openai.com\n\n  *.example.com , 192.168.1.20\r\n[::1]\napi.openai.com")
        );
    }

    /** @dataProvider invalidHostEntries */
    public function testInvalidHostEntries(string $entry): void
    {
        $this->assertFalse(UrlGuard::isValidHostEntry($entry), $entry);
    }

    public static function invalidHostEntries(): array
    {
        return [
            'url'           => ['https://api.openai.com'],
            'path'          => ['api.openai.com/v1'],
            'port'          => ['api.openai.com:443'],
            'bare wildcard' => ['*'],
            'mid wildcard'  => ['api.*.com'],
            'space'         => ['api openai.com'],
        ];
    }

    public function testValidHostEntries(): void
    {
        foreach (['api.openai.com', '*.example.com', 'ollama', '192.168.1.20', '::1', 'fd12::1'] as $entry) {
            $this->assertTrue(UrlGuard::isValidHostEntry($entry), $entry);
        }
    }

    public function testFromConfigReadsTheSettings(): void
    {
        $this->container['configModel']->save([
            UrlGuard::ALLOWED_HOSTS_KEY => "ollama.lan",
            UrlGuard::ALLOW_PRIVATE_KEY => '1',
        ]);
        $this->container['memoryCache']->flush();
        $this->dns(['ollama.lan' => ['192.168.1.20'], 'other.lan' => ['192.168.1.21']]);

        $guard = UrlGuard::fromConfig($this->container['configModel']);
        $this->assertSame('192.168.1.20', $guard->check('http://ollama.lan/v1')['ip']);
        $this->assertRefused($guard, 'http://other.lan/v1', UnsafeUrlException::HOST_NOT_ALLOWED);
    }

    public function testFromConfigDefaultsToAnyPublicHostAndNoLan(): void
    {
        $this->dns(['ollama.lan' => ['192.168.1.20'], 'api.example.com' => ['93.184.216.34']]);
        $guard = UrlGuard::fromConfig($this->container['configModel']);
        $this->assertSame('93.184.216.34', $guard->check('https://api.example.com/v1')['ip']);
        $this->assertRefused($guard, 'http://ollama.lan/v1', UnsafeUrlException::PRIVATE_NETWORK);
    }

    public function testExceptionMessageIsSafeToShow(): void
    {
        $this->dns(['evil.example' => ['169.254.169.254']]);
        try {
            (new UrlGuard())->check('http://evil.example/latest/meta-data?token=secret');
            $this->fail('expected refusal');
        } catch (UnsafeUrlException $e) {
            $this->assertStringContainsString('evil.example', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testHttpClientIsPinnedAndDoesNotFollowRedirects(): void
    {
        $this->dns(['api.example.com' => ['93.184.216.34']]);
        require_once dirname(__DIR__) . '/vendor/autoload.php';

        $client = (new UrlGuard())->httpClient('https://api.example.com/v1', ['timeout' => 7]);
        $ref = new \ReflectionProperty($client, 'defaultOptions');
        $opts = $ref->getValue($client);

        $this->assertSame(['api.example.com' => '93.184.216.34'], $opts['resolve']);
        $this->assertSame(0, $opts['max_redirects']);
        $this->assertSame(7.0, (float) $opts['timeout']);
    }

    public function testHttpClientRefusesBeforeConnecting(): void
    {
        $this->dns(['evil.example' => ['169.254.169.254']]);
        $this->expectException(UnsafeUrlException::class);
        (new UrlGuard())->httpClient('http://evil.example/');
    }
}
