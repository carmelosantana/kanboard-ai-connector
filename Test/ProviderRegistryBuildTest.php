<?php

require_once 'tests/units/Base.php';

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Model\UnsafeUrlException;
use Kanboard\Plugin\AiConnector\Model\UrlGuard;
use KanboardTests\units\Base;

/**
 * Task 2 — buildProvider (all 7 types) + structured() normalization. No network.
 */
class ProviderRegistryBuildTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        UrlGuard::setResolverForTesting(fn (string $host): array => match ($host) {
            'api.openai.com'  => ['162.159.140.245'],
            'localhost'       => ['127.0.0.1'],
            'gw.example.com'  => ['93.184.216.34'],
            'evil.example'    => ['169.254.169.254'],
            default           => [],
        });
    }

    protected function tearDown(): void
    {
        UrlGuard::setResolverForTesting(null);
        parent::tearDown();
    }

    private function seed(string $option, string $value): void
    {
        $db = $this->container['db'];
        if ($db->table('settings')->eq('option', $option)->count() > 0) {
            $db->table('settings')->eq('option', $option)->update(['value' => $value]);
        } else {
            $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        }
        $this->container['memoryCache']->flush();
    }

    private function seedOne(string $provider, string $model, string $key = 'k', string $baseUrl = ''): ProviderRegistry
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'P', 'provider' => $provider, 'model' => $model, 'base_url' => $baseUrl],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        if ($key !== '') {
            $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', $key);
        }
        return new ProviderRegistry($this->container);
    }

    public function testBuildEachProviderType(): void
    {
        $this->seed(UrlGuard::ALLOW_PRIVATE_KEY, '1'); // the Ollama default is localhost
        $cases = [
            [ProviderRegistry::PROVIDER_ANTHROPIC,        'claude-sonnet-4-20250514', AnthropicProvider::class],
            [ProviderRegistry::PROVIDER_OPENAI,           'gpt-4o',                   OpenAICompatibleProvider::class],
            [ProviderRegistry::PROVIDER_OPENAI_RESPONSES, 'gpt-5',                    OpenAIResponsesProvider::class],
            [ProviderRegistry::PROVIDER_GROK,             'grok-3',                   XAIProvider::class],
            [ProviderRegistry::PROVIDER_GEMINI,           'gemini-2.5-flash',         GeminiProvider::class],
            [ProviderRegistry::PROVIDER_MISTRAL,          'mistral-large-latest',     MistralProvider::class],
            [ProviderRegistry::PROVIDER_OLLAMA,           'llama3.2',                 OllamaProvider::class],
        ];
        foreach ($cases as [$type, $model, $class]) {
            $r = $this->seedOne($type, $model, $type === 'ollama' ? '' : 'k');
            $this->assertInstanceOf($class, $r->buildProvider(), "provider type $type must build $class");
        }
    }

    public function testBuildDefaultsToDefaultProfileWhenIdNull(): void
    {
        $r = $this->seedOne(ProviderRegistry::PROVIDER_ANTHROPIC, 'm', 'k');
        $this->assertInstanceOf(AnthropicProvider::class, $r->buildProvider(null));
    }

    public function testBuildThrowsWhenNoProfilesAndNoDefault(): void
    {
        $r = new ProviderRegistry($this->container);
        $this->expectException(\RuntimeException::class);
        $r->buildProvider();
    }

    public function testBuildThrowsOnUnknownIdWithoutLeakingKey(): void
    {
        $r = $this->seedOne(ProviderRegistry::PROVIDER_ANTHROPIC, 'm', 'super-secret-key');
        try {
            $r->buildProvider('ghost');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('super-secret-key', $e->getMessage());
        }
    }

    public function testBuildThrowsOnUnsupportedProviderType(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'X', 'provider' => 'llamacpp', 'model' => 'm', 'base_url' => ''],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        $r = new ProviderRegistry($this->container);
        $this->expectException(\RuntimeException::class);
        $r->buildProvider('p1');
    }

    // ── structured() normalization via injected fake provider ─────────────────

    private function fakeProviderReturning(mixed $value): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('structured')->willReturn($value);
        return $mock;
    }

    private function registryWithFake(ProviderInterface $p): ProviderRegistry
    {
        $r = new ProviderRegistry($this->container);
        $r->setProviderForTesting($p);
        return $r;
    }

    public function testStructuredNormalizesArrayResult(): void
    {
        $r = $this->registryWithFake($this->fakeProviderReturning(['subtasks' => [['title' => 'A']]]));
        $out = $r->structured([['role' => 'user', 'content' => 'hi']], '{}');
        $this->assertSame(['subtasks' => [['title' => 'A']]], $out);
    }

    public function testStructuredNormalizesResponseResult(): void
    {
        $resp = new Response(content: json_encode(['ok' => true]), finishReason: ProviderFinishReason::Stop);
        $r = $this->registryWithFake($this->fakeProviderReturning($resp));
        $out = $r->structured([['role' => 'user', 'content' => 'hi']], '{}');
        $this->assertSame(['ok' => true], $out);
    }

    public function testStructuredReturnsEmptyOnNull(): void
    {
        $r = $this->registryWithFake($this->fakeProviderReturning(null));
        $this->assertSame([], $r->structured([['role' => 'user', 'content' => 'x']], '{}'));
    }

    public function testStructuredReturnsEmptyOnResponseWithBadJson(): void
    {
        $resp = new Response(content: 'NOT_JSON{{', finishReason: ProviderFinishReason::Stop);
        $r = $this->registryWithFake($this->fakeProviderReturning($resp));
        $this->assertSame([], $r->structured([['role' => 'user', 'content' => 'x']], '{}'));
    }

    public function testStructuredMapsAllRolesWithoutError(): void
    {
        $r = $this->registryWithFake($this->fakeProviderReturning(['ok' => 1]));
        $out = $r->structured([
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'u'],
            ['role' => 'assistant', 'content' => 'a'],
            ['role' => 'weird', 'content' => 'fallback-to-user'],
        ], '{}');
        $this->assertSame(['ok' => 1], $out);
    }

    // ── SSRF guard ──────────────────────────────────────────────────────────────

    private function pinnedResolve(ProviderInterface $provider): array
    {
        $client = (new \ReflectionProperty(\CarmeloSantana\PHPAgents\Provider\AbstractProvider::class, 'httpClient'))->getValue($provider);
        $opts = (new \ReflectionProperty($client, 'defaultOptions'))->getValue($client);
        $this->assertSame(0, $opts['max_redirects']);
        return $opts['resolve'];
    }

    public function testBaseUrlProvidersGetAClientPinnedToTheCheckedAddress(): void
    {
        $r = $this->seedOne(ProviderRegistry::PROVIDER_OPENAI, 'gpt-4o', 'k', 'https://gw.example.com/v1');
        $this->assertSame(['gw.example.com' => '93.184.216.34'], $this->pinnedResolve($r->buildProvider()));

        $r = $this->seedOne(ProviderRegistry::PROVIDER_OPENAI_RESPONSES, 'gpt-5', 'k');
        $this->assertSame(['api.openai.com' => '162.159.140.245'], $this->pinnedResolve($r->buildProvider()));

        $this->seed(UrlGuard::ALLOW_PRIVATE_KEY, '1');
        $r = $this->seedOne(ProviderRegistry::PROVIDER_OLLAMA, 'llama3.2', '');
        $this->assertSame(['localhost' => '127.0.0.1'], $this->pinnedResolve($r->buildProvider()));
    }

    public function testMetadataBaseUrlIsRefusedWithoutLeakingTheKey(): void
    {
        $this->seed(UrlGuard::ALLOW_PRIVATE_KEY, '1');
        $r = $this->seedOne(ProviderRegistry::PROVIDER_OPENAI, 'gpt-4o', 'super-secret-key', 'http://evil.example/v1');
        try {
            $r->buildProvider();
            $this->fail('expected UnsafeUrlException');
        } catch (UnsafeUrlException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
            $this->assertSame(UnsafeUrlException::BLOCKED_ADDRESS, $e->reason);
            $this->assertStringNotContainsString('super-secret-key', $e->getMessage());
        }
    }

    public function testLocalOllamaNeedsTheLanOptIn(): void
    {
        $r = $this->seedOne(ProviderRegistry::PROVIDER_OLLAMA, 'llama3.2', '');
        $this->expectException(UnsafeUrlException::class);
        $r->buildProvider();
    }

    public function testOllamaHostEnvIsGuardedToo(): void
    {
        putenv('OLLAMA_HOST=http://evil.example:11434');
        try {
            $this->seed(UrlGuard::ALLOW_PRIVATE_KEY, '1');
            $r = $this->seedOne(ProviderRegistry::PROVIDER_OLLAMA, 'llama3.2', '');
            $this->expectException(UnsafeUrlException::class);
            $r->buildProvider();
        } finally {
            putenv('OLLAMA_HOST');
        }
    }

    public function testAllowListAppliesToBaseUrlProviders(): void
    {
        $this->seed(UrlGuard::ALLOWED_HOSTS_KEY, 'api.openai.com');
        $r = $this->seedOne(ProviderRegistry::PROVIDER_OPENAI, 'gpt-4o', 'k', 'https://gw.example.com/v1');
        $this->expectException(UnsafeUrlException::class);
        $r->buildProvider();
    }

    public function testFixedHostProvidersAreNotAffectedByTheAllowList(): void
    {
        $this->seed(UrlGuard::ALLOWED_HOSTS_KEY, 'api.openai.com');
        $r = $this->seedOne(ProviderRegistry::PROVIDER_ANTHROPIC, 'm', 'k');
        $this->assertInstanceOf(AnthropicProvider::class, $r->buildProvider());
    }
}
