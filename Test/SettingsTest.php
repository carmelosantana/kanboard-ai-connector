<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AiConnector\Controller\SettingsController;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/**
 * Task 3 — settings CRUD, admin gate, key masking. No network.
 */
class SettingsTest extends Base
{
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

    private function stubNonAdmin(): void
    {
        $this->container['userSession'] = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['isAdmin'])->getMock();
        $this->container['userSession']->method('isAdmin')->willReturn(false);
    }

    private function stubAdminWithForm(array $formValues, bool $csrfValid = true): void
    {
        $this->container['userSession'] = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['isAdmin', 'getId'])->getMock();
        $this->container['userSession']->method('isAdmin')->willReturn(true);
        $this->container['userSession']->method('getId')->willReturn(1);

        $this->container['token'] = $this->getMockBuilder(\Kanboard\Core\Security\Token::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['validateCSRFToken'])->getMock();
        $this->container['token']->method('validateCSRFToken')->willReturn($csrfValid);

        $this->container['request'] = $this->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getValues', 'getRawValue', 'getStringParam'])->getMock();
        $this->container['request']->method('getValues')->willReturn($formValues);
        $this->container['request']->method('getRawValue')->willReturn('dummy-csrf');
        $this->container['request']->method('getStringParam')->willReturnCallback(
            fn (string $p, $d = '') => $formValues[$p] ?? $d
        );
    }

    private function driveSave(): void
    {
        try {
            (new SettingsController($this->container))->save();
        } catch (\Throwable $e) {
            // redirect() exits/throws in test context — expected.
        }
    }

    private function driveDelete(): void
    {
        try {
            (new SettingsController($this->container))->delete();
        } catch (\Throwable $e) {
            // redirect() exits/throws in test context — expected.
        }
    }

    private function driveSetDefault(): void
    {
        try {
            (new SettingsController($this->container))->setDefault();
        } catch (\Throwable $e) {
            // redirect() exits/throws in test context — expected.
        }
    }

    public function testShowThrowsForNonAdmin(): void
    {
        $this->stubNonAdmin();
        $this->expectException(AccessForbiddenException::class);
        (new SettingsController($this->container))->show();
    }

    public function testSaveThrowsForNonAdmin(): void
    {
        $this->stubNonAdmin();
        $this->expectException(AccessForbiddenException::class);
        (new SettingsController($this->container))->save();
    }

    public function testDeleteThrowsForNonAdmin(): void
    {
        $this->stubNonAdmin();
        $this->expectException(AccessForbiddenException::class);
        (new SettingsController($this->container))->delete();
    }

    public function testSetDefaultThrowsForNonAdmin(): void
    {
        $this->stubNonAdmin();
        $this->expectException(AccessForbiddenException::class);
        (new SettingsController($this->container))->setDefault();
    }

    public function testSaveCreatesFirstProfileAsDefaultWithKey(): void
    {
        $this->stubAdminWithForm([
            'profile_id' => '',
            'label'      => 'Claude',
            'provider'   => 'anthropic',
            'model'      => 'claude-sonnet-4-20250514',
            'base_url'   => '',
            'api_key'    => 'sk-real',
        ]);
        $this->driveSave();

        $r = new ProviderRegistry($this->container);
        $profiles = $r->getProfiles();
        $this->assertCount(1, $profiles);
        $id = $profiles[0]['id'];
        $this->assertSame('anthropic', $profiles[0]['provider']);
        $this->assertSame($id, $r->getDefaultProfileId());
        $this->assertSame('sk-real', $this->container['configModel']->get(ProviderRegistry::KEY_PREFIX . $id, ''));
        // Key must NOT be inside the profiles JSON.
        $this->assertStringNotContainsString('sk-real', (string) $this->container['configModel']->get(ProviderRegistry::PROFILES_KEY, ''));
    }

    public function testSaveWithBlankKeyPreservesStoredKey(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => ''],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', 'kept-key');

        $this->stubAdminWithForm([
            'profile_id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic',
            'model' => 'm', 'base_url' => '', 'api_key' => '',
        ]);
        $this->driveSave();

        $this->assertSame('kept-key', $this->container['configModel']->get(ProviderRegistry::KEY_PREFIX . 'p1', ''));
    }

    public function testSaveWithPlaceholderKeyPreservesStoredKey(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => ''],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', 'kept-key-2');

        $this->stubAdminWithForm([
            'profile_id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic',
            'model' => 'm', 'base_url' => '', 'api_key' => ProviderRegistry::KEY_PLACEHOLDER,
        ]);
        $this->driveSave();

        $this->assertSame('kept-key-2', $this->container['configModel']->get(ProviderRegistry::KEY_PREFIX . 'p1', ''));
    }

    public function testSaveRejectsUnknownProviderType(): void
    {
        $this->stubAdminWithForm([
            'profile_id' => '', 'label' => 'Bad', 'provider' => 'llamacpp',
            'model' => 'm', 'base_url' => '', 'api_key' => 'k',
        ]);
        $this->driveSave();
        // A profile with an unsupported provider must NOT be persisted.
        $this->assertSame([], (new ProviderRegistry($this->container))->getProfiles());
    }

    public function testTemplateNeverEchoesStoredKey(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/Template/config/settings.php');
        $this->assertMatchesRegularExpression('/name="api_key"[^>]*value=""/', $content,
            'api_key input must render value="" (never a stored key)');
        $this->assertStringNotContainsString('$this->token', $content,
            '$this->token is not a template helper — would break layout');
    }

    public function testDeleteRemovesProfileAndKey(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => ''],
            ['id' => 'p2', 'label' => 'GPT', 'provider' => 'openai', 'model' => 'm2', 'base_url' => ''],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', 'p1-key');
        $this->seed(ProviderRegistry::KEY_PREFIX . 'p2', 'p2-key');

        $this->stubAdminWithForm(['profile_id' => 'p1']);
        $this->driveDelete();

        $r = new ProviderRegistry($this->container);
        $profiles = $r->getProfiles();
        $this->assertCount(1, $profiles);
        $this->assertSame('p2', $profiles[0]['id']);
        // Key removed for the deleted profile.
        $this->assertSame('', $this->container['configModel']->get(ProviderRegistry::KEY_PREFIX . 'p1', ''));
        // Default was p1 (now deleted) — must reassign to the remaining profile.
        $this->assertSame('p2', $r->getDefaultProfileId());
    }

    public function testSetDefaultChangesDefault(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => ''],
            ['id' => 'p2', 'label' => 'GPT', 'provider' => 'openai', 'model' => 'm2', 'base_url' => ''],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');

        $this->stubAdminWithForm(['profile_id' => 'p2']);
        $this->driveSetDefault();

        $r = new ProviderRegistry($this->container);
        $this->assertSame('p2', $r->getDefaultProfileId());
    }

    public function testSetDefaultWithUnknownIdDoesNotChangeDefault(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => ''],
            ['id' => 'p2', 'label' => 'GPT', 'provider' => 'openai', 'model' => 'm2', 'base_url' => ''],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');

        $this->stubAdminWithForm(['profile_id' => 'does-not-exist']);
        $this->driveSetDefault();

        // Unknown id is rejected; the seeded default must be untouched.
        $this->assertSame('p1', (new ProviderRegistry($this->container))->getDefaultProfileId());
    }

    public function testSaveRejectsBadCsrf(): void
    {
        // Admin session but an invalid CSRF token — checkCSRFForm() must throw.
        $this->stubAdminWithForm([
            'profile_id' => '', 'label' => 'Claude', 'provider' => 'anthropic',
            'model' => 'm', 'base_url' => '', 'api_key' => 'sk-real',
        ], false);

        $this->expectException(AccessForbiddenException::class);
        (new SettingsController($this->container))->save();
    }

    public function testTestConnectionNonAdminReturnsError(): void
    {
        // Non-admin path returns a JSON error (ok=false) rather than throwing
        // AccessForbiddenException; the endpoint is also GET-CSRF gated. We assert
        // the source guards to keep the behavior verifiable without HTTP.
        $src = file_get_contents(dirname(__DIR__) . '/Controller/SettingsController.php');
        $this->assertStringContainsString('checkReusableGETCSRFParam', $src,
            'testConnection() must validate the reusable CSRF token from the GET param');
        $this->assertStringContainsString('isAdmin', $src);
    }

    public function testTestConnectionResponseNeverIncludesRawResult(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/SettingsController.php');
        $this->assertStringNotContainsString("'result' =>", $src,
            'testConnection() must not echo the raw model output');
    }

    public function testSettingsTemplatePassesCsrfTokenToTestConnection(): void
    {
        $template   = file_get_contents(dirname(__DIR__) . '/Template/config/settings.php');
        $controller = file_get_contents(dirname(__DIR__) . '/Controller/SettingsController.php');
        $this->assertStringContainsString('csrf_token', $template);
        $this->assertStringContainsString('$ai_test_csrf', $template);
        $this->assertStringNotContainsString('$this->token', $template);
        $this->assertStringContainsString('getReusableCSRFToken', $controller);
    }

    public function testTestConnectionAssetExists(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/Assets/js/ai-connector.js');
    }
}
