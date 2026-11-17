<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Controller\SettingsController;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/**
 * A stored key stays with the endpoint it was entered for; base URLs are
 * validated; Test Connection returns a generic error. No network.
 */
class SettingsEndpointTest extends Base
{
    private array $json = [];
    private array $flashes = [];

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

    private function seedProfile(string $provider, string $baseUrl, string $key = 'stored-key'): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'P', 'provider' => $provider, 'model' => 'm', 'base_url' => $baseUrl],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', $key);
    }

    private function stubAdmin(array $formValues, array $query = []): void
    {
        $session = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['isAdmin', 'getId'])->getMock();
        $session->method('isAdmin')->willReturn(true);
        $session->method('getId')->willReturn(1);
        $this->container['userSession'] = $session;

        $token = $this->getMockBuilder(\Kanboard\Core\Security\Token::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['validateCSRFToken', 'validateReusableCSRFToken'])->getMock();
        $token->method('validateCSRFToken')->willReturn(true);
        $token->method('validateReusableCSRFToken')->willReturn(true);
        $this->container['token'] = $token;

        $request = $this->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getValues', 'getRawValue', 'getStringParam'])->getMock();
        $request->method('getValues')->willReturn($formValues);
        $request->method('getRawValue')->willReturn('dummy-csrf');
        $request->method('getStringParam')->willReturnCallback(fn (string $p, $d = '') => $query[$p] ?? $d);
        $this->container['request'] = $request;

        $flash = $this->getMockBuilder(\Kanboard\Core\Session\FlashMessage::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['success', 'failure'])->getMock();
        $flash->method('success')->willReturnCallback(function ($m) { $this->flashes['success'] = $m; });
        $flash->method('failure')->willReturnCallback(function ($m) { $this->flashes['failure'] = $m; });
        $this->container['flash'] = $flash;

        $response = $this->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['redirect', 'json'])->getMock();
        $response->method('json')->willReturnCallback(function ($data) { $this->json = $data; });
        $this->container['response'] = $response;
    }

    private function save(array $values): void
    {
        $this->stubAdmin($values + ['profile_id' => 'p1', 'label' => 'P', 'model' => 'm', 'api_key' => '']);
        (new SettingsController($this->container))->save();
    }

    private function storedKey(): string
    {
        return (string) $this->container['configModel']->get(ProviderRegistry::KEY_PREFIX . 'p1', '');
    }

    private function storedBaseUrl(): string
    {
        return (string) (new ProviderRegistry($this->container))->findProfile('p1')['base_url'];
    }

    public function testChangingTheBaseUrlHostClearsTheStoredKey(): void
    {
        $this->seedProfile('openai', 'https://api.openai.com/v1');
        $this->save(['provider' => 'openai', 'base_url' => 'https://other.example.com/v1']);

        $this->assertSame('', $this->storedKey());
        $this->assertSame('https://other.example.com/v1', $this->storedBaseUrl());
        $this->assertStringContainsString('API key', $this->flashes['success']);
    }

    public function testChangingFromTheDefaultEndpointClearsTheStoredKey(): void
    {
        $this->seedProfile('openai', '');
        $this->save(['provider' => 'openai', 'base_url' => 'https://other.example.com/v1']);

        $this->assertSame('', $this->storedKey());
    }

    public function testChangingTheProviderClearsTheStoredKey(): void
    {
        $this->seedProfile('anthropic', '');
        $this->save(['provider' => 'openai', 'base_url' => '']);

        $this->assertSame('', $this->storedKey());
    }

    public function testChangingOnlyThePathKeepsTheStoredKey(): void
    {
        $this->seedProfile('openai', 'https://api.openai.com/v1');
        $this->save(['provider' => 'openai', 'base_url' => 'https://api.openai.com/v2']);

        $this->assertSame('stored-key', $this->storedKey());
    }

    public function testSpellingOutTheDefaultEndpointKeepsTheStoredKey(): void
    {
        $this->seedProfile('openai', '');
        $this->save(['provider' => 'openai', 'base_url' => 'https://API.openai.com/v1']);

        $this->assertSame('stored-key', $this->storedKey());
    }

    public function testANewKeySubmittedWithTheHostChangeIsStored(): void
    {
        $this->seedProfile('openai', 'https://api.openai.com/v1');
        $this->save(['provider' => 'openai', 'base_url' => 'https://other.example.com/v1', 'api_key' => 'new-key']);

        $this->assertSame('new-key', $this->storedKey());
    }

    public function testBaseUrlIgnoredByTheProviderKeepsTheStoredKey(): void
    {
        $this->seedProfile('anthropic', '');
        $this->save(['provider' => 'anthropic', 'base_url' => 'https://other.example.com']);

        $this->assertSame('stored-key', $this->storedKey());
    }

    /** @dataProvider invalidBaseUrls */
    public function testInvalidBaseUrlIsRefused(string $url): void
    {
        $this->seedProfile('openai', 'https://api.openai.com/v1');
        $this->save(['provider' => 'openai', 'base_url' => $url]);

        $this->assertArrayHasKey('failure', $this->flashes);
        $this->assertSame('https://api.openai.com/v1', $this->storedBaseUrl());
        $this->assertSame('stored-key', $this->storedKey());
    }

    public static function invalidBaseUrls(): array
    {
        return [
            'ftp scheme'     => ['ftp://example.com/v1'],
            'javascript'     => ['javascript:alert(1)'],
            'no host'        => ['https://'],
            'relative'       => ['/v1'],
            'credentials'    => ['https://user:pass@example.com/v1'],
            'file'           => ['file:///etc/passwd'],
        ];
    }

    public function testValidHttpBaseUrlIsAccepted(): void
    {
        $this->seedProfile('ollama', '', '');
        $this->save(['provider' => 'ollama', 'base_url' => 'http://192.168.1.20:11434/v1']);

        $this->assertArrayNotHasKey('failure', $this->flashes);
        $this->assertSame('http://192.168.1.20:11434/v1', $this->storedBaseUrl());
    }

    public function testTestConnectionReturnsAGenericError(): void
    {
        $this->stubAdmin([], ['profile' => 'no-such-profile-xyz', 'csrf_token' => 't']);
        (new SettingsController($this->container))->testConnection();

        $this->assertFalse($this->json['ok']);
        $this->assertStringNotContainsString('no-such-profile-xyz', $this->json['error']);
        $this->assertStringNotContainsString('AiConnector]', $this->json['error']);
    }
}
