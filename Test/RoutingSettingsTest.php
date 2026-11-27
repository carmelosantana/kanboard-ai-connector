<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AiConnector\Controller\AiConnectorSettingsController;
use Kanboard\Plugin\AiConnector\Model\ModelRouting;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/** The Model routing form on the AiConnector settings page: admin only, CSRF checked, all-or-nothing. */
class RoutingSettingsTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'A', 'label' => 'A', 'provider' => 'ollama', 'model' => 'm', 'base_url' => ''],
            ['id' => 'B', 'label' => 'B', 'provider' => 'ollama', 'model' => 'm', 'base_url' => ''],
            ['id' => 'C', 'label' => 'C', 'provider' => 'ollama', 'model' => 'm', 'base_url' => '', 'available' => true],
            ['id' => 'D', 'label' => 'D', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => ''],
        ]));
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'A');
        foreach (['ANTHROPIC_API_KEY'] as $v) {
            putenv($v . '=');
        }
        $this->container['hook']->on(ProviderRegistry::FEATURES_HOOK, fn () => [['id' => 't.one', 'label' => 'One', 'plugin' => 'T']]);
    }

    private function setting(string $option, string $value): void
    {
        $db = $this->container['db'];
        $db->table('settings')->eq('option', $option)->remove();
        $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        $this->container['memoryCache']->flush();
    }

    private function stubSession(bool $admin, array $form = [], bool $csrfValid = true): void
    {
        $this->container['userSession'] = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['isAdmin', 'getId'])->getMock();
        $this->container['userSession']->method('isAdmin')->willReturn($admin);
        $this->container['userSession']->method('getId')->willReturn(1);

        $this->container['token'] = $this->getMockBuilder(\Kanboard\Core\Security\Token::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['validateCSRFToken'])->getMock();
        $this->container['token']->method('validateCSRFToken')->willReturn($csrfValid);

        $this->container['request'] = $this->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getValues', 'getRawValue'])->getMock();
        $this->container['request']->method('getValues')->willReturn($form);
        $this->container['request']->method('getRawValue')->willReturn('dummy-csrf');
    }

    private function saveRouting(): void
    {
        try {
            (new AiConnectorSettingsController($this->container))->saveRouting();
        } catch (\Throwable $e) {
            if ($e instanceof AccessForbiddenException) {
                throw $e;
            }
            // redirect() exits/throws in test context — expected.
        }
        $this->container['memoryCache']->flush();
    }

    private function available(): array
    {
        $out = [];
        foreach ((new ProviderRegistry($this->container))->getProfiles() as $p) {
            $out[$p['id']] = $p['available'];
        }
        return $out;
    }

    private function validForm(): array
    {
        return [
            'plugins' => ['T' => 'B'],
            'features' => ['t.one' => 'C'],
            'locked' => ['t.one'],
            'available' => ['A' => '1', 'B' => '1'],
        ];
    }

    public function testNonAdminIsRefused(): void
    {
        $this->stubSession(false, $this->validForm());
        $this->expectException(AccessForbiddenException::class);
        $this->saveRouting();
    }

    public function testBadCsrfIsRefused(): void
    {
        $this->stubSession(true, $this->validForm(), false);
        $this->expectException(AccessForbiddenException::class);
        $this->saveRouting();
    }

    public function testAdminSavesAssignmentsAndAvailableFlags(): void
    {
        $this->stubSession(true, $this->validForm());
        $this->saveRouting();

        $this->assertSame([
            'plugins' => ['T' => 'B'],
            'features' => ['t.one' => 'C'],
            'locked' => ['t.one'],
        ], (new ModelRouting($this->container))->assignments());
        $this->assertSame(['A' => true, 'B' => true, 'C' => false, 'D' => false], $this->available());
    }

    public function testAnUnknownProfileSavesNothing(): void
    {
        $form = $this->validForm();
        $form['features']['t.one'] = 'ghost';
        $this->stubSession(true, $form);
        $this->saveRouting();

        $this->assertSame([], (new ModelRouting($this->container))->assignments()['plugins']);
        $this->assertSame(['A' => false, 'B' => false, 'C' => true, 'D' => false], $this->available());
    }

    public function testAnUnknownAvailableProfileSavesNothing(): void
    {
        $form = $this->validForm();
        $form['available']['ghost'] = '1';
        $this->stubSession(true, $form);
        $this->saveRouting();

        $this->assertSame([], (new ModelRouting($this->container))->assignments()['plugins']);
        $this->assertSame(['A' => false, 'B' => false, 'C' => true, 'D' => false], $this->available());
    }

    public function testBrokenAssignmentsAreListed(): void
    {
        $this->setting(ModelRouting::CONFIG_KEY, json_encode(['plugins' => ['T' => 'D'], 'features' => ['t.one' => 'zz']]));

        $this->assertSame([
            ['level' => 'plugins', 'key' => 'T', 'profile' => 'D', 'reason' => 'not ready'],
            ['level' => 'features', 'key' => 't.one', 'profile' => 'zz', 'reason' => 'missing'],
        ], (new ProviderRegistry($this->container))->brokenAssignments());
    }

    public function testTheRouteControllerAndTemplateAreWired(): void
    {
        $this->assertStringContainsString("'ai-connector/routing'", file_get_contents(dirname(__DIR__).'/Plugin.php'));
        $controller = file_get_contents(dirname(__DIR__).'/Controller/AiConnectorSettingsController.php');
        $this->assertStringContainsString("'routing_broken'", $controller);
        $template = file_get_contents(dirname(__DIR__).'/Template/config/settings.php');
        foreach (["'saveRouting'", "t('Model routing')", "t('Available to users')", "t('Lock')"] as $needle) {
            $this->assertStringContainsString($needle, $template);
        }
    }
    public function testOneFeatureListIsUsedForTheWholeSave(): void
    {
        // A listener whose features change between calls must not leave a half-saved form.
        $calls = 0;
        $this->container['hook']->on(ProviderRegistry::FEATURES_HOOK, function () use (&$calls) {
            return ++$calls === 1 ? [['id' => 't.two', 'label' => 'Two', 'plugin' => 'T']] : [];
        });
        $form = $this->validForm();
        $form['locked'][] = 't.two';
        $this->stubSession(true, $form);
        $this->saveRouting();

        $this->assertSame(['t.one', 't.two'], (new ModelRouting($this->container))->assignments()['locked']);
        $this->assertSame(1, $calls);
    }
}
