<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AiConnector\Controller\AiConnectorProjectController;
use Kanboard\Plugin\AiConnector\Model\ModelRouting;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Plugin;
use KanboardTests\units\Base;

/** The project "AI" page: project managers choose the project's profile and the members' switch. */
class ProjectRoutingTest extends Base
{
    private int $project;
    private array $html = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'A', 'label' => 'Alpha', 'provider' => 'ollama', 'model' => 'm', 'base_url' => ''],
            ['id' => 'B', 'label' => 'Beta', 'provider' => 'ollama', 'model' => 'm', 'base_url' => '', 'available' => true],
            ['id' => 'C', 'label' => 'Gamma', 'provider' => 'ollama', 'model' => 'm', 'base_url' => ''],
        ]));
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'A');
        $this->container['hook']->on(ProviderRegistry::FEATURES_HOOK, fn () => [
            ['id' => 't.one', 'label' => 'Feature one', 'plugin' => 'T'],
            ['id' => 't.two', 'label' => 'Feature two', 'plugin' => 'T'],
        ]);
        $this->setting(ModelRouting::CONFIG_KEY, json_encode(['features' => ['t.two' => 'C'], 'locked' => ['t.two']]));
        $this->project = (int) $this->container['projectModel']->create(['name' => 'Routed']);
    }

    private function setting(string $option, string $value): void
    {
        $db = $this->container['db'];
        $db->table('settings')->eq('option', $option)->remove();
        $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        $this->container['memoryCache']->flush();
    }

    private function stub(array $form = [], bool $csrfValid = true): void
    {
        $this->container['token'] = $this->getMockBuilder(\Kanboard\Core\Security\Token::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['validateCSRFToken'])->getMock();
        $this->container['token']->method('validateCSRFToken')->willReturn($csrfValid);

        $this->container['request'] = $this->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getValues', 'getRawValue', 'getIntegerParam'])->getMock();
        $this->container['request']->method('getValues')->willReturn($form);
        $this->container['request']->method('getRawValue')->willReturn('dummy-csrf');
        $this->container['request']->method('getIntegerParam')->willReturnCallback(fn ($name, $default = 0) => $name === 'project_id' ? $this->project : $default);

        $response = $this->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['redirect', 'html'])->getMock();
        $response->method('html')->willReturnCallback(function ($html) { $this->html[] = $html; });
        $this->container['response'] = $response;
    }

    private function save(array $form, bool $csrfValid = true): void
    {
        $this->stub($form, $csrfValid);
        try {
            (new AiConnectorProjectController($this->container))->saveProject();
        } catch (\Throwable $e) {
            if ($e instanceof AccessForbiddenException) {
                throw $e;
            }
        }
    }

    private function routing(): ModelRouting
    {
        return new ModelRouting($this->container);
    }

    public function testOnlyProjectManagersReachThePage(): void
    {
        (new Plugin($this->container))->initialize();
        $map = $this->container['projectAccessMap'];
        foreach (['show', 'saveProject'] as $action) {
            $roles = $map->getRoles('AiConnectorProjectController', $action);
            $this->assertContains(Role::PROJECT_MANAGER, $roles, $action);
            $this->assertNotContains(Role::PROJECT_MEMBER, $roles, $action);
            $this->assertNotContains(Role::PROJECT_VIEWER, $roles, $action);
        }
    }

    public function testBadCsrfIsRefused(): void
    {
        $this->expectException(AccessForbiddenException::class);
        $this->save(['ai_profile' => 'B', 'members_switch' => '1'], false);
    }

    public function testAValidSaveStoresProfileAndSwitch(): void
    {
        $this->save(['ai_profile' => 'B', 'members_switch' => '1']);

        $this->assertSame('B', $this->routing()->projectProfile($this->project));
        $this->assertTrue($this->routing()->membersMaySwitch($this->project));
    }

    public function testAProfileThatIsNotOfferedIsRefused(): void
    {
        // C exists and is ready but is not available to users.
        $this->save(['ai_profile' => 'C', 'members_switch' => '1']);

        $this->assertSame('', $this->routing()->projectProfile($this->project));
        $this->assertFalse($this->routing()->membersMaySwitch($this->project));
    }

    public function testThePageOffersAvailableProfilesAndListsLockedFeatures(): void
    {
        $this->stub();
        // The project layout renders the header, which needs a signed-in user.
        $_SESSION['user'] = $this->container['userModel']->getById(1);
        (new AiConnectorProjectController($this->container))->show();
        $html = implode('', $this->html);

        $this->assertStringContainsString('name="ai_profile"', $html);
        $this->assertStringContainsString('>Beta<', $html);
        $this->assertStringNotContainsString('>Gamma<', $html);
        $this->assertStringContainsString('name="members_switch"', $html);
        $this->assertStringContainsString('Feature two', $html);
    }

    public function testTheSidebarLinksToThePage(): void
    {
        $sidebar = file_get_contents(dirname(__DIR__).'/Template/project/sidebar.php');
        $this->assertStringContainsString("'AiConnectorProjectController'", $sidebar);
        $this->assertStringContainsString('hasProjectAccess', $sidebar);
        $this->assertStringContainsString("'ai-connector/project/:project_id'", file_get_contents(dirname(__DIR__).'/Plugin.php'));
    }
}
