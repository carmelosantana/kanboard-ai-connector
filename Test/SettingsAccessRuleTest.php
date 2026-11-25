<?php

namespace Kanboard\Plugin\AiConnector\Test;

require_once 'tests/units/Base.php';

use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AiConnector\Plugin;
use KanboardTests\units\Base;

/**
 * The settings controller used to share core's name `SettingsController` and so inherited its
 * admin-only rule. Renamed (#7337), it needs its own rule or it falls to the app-user default.
 */
class SettingsAccessRuleTest extends Base
{
    public function testEverySettingsActionIsAdminOnlyInTheAccessMap(): void
    {
        $this->container['hook'] = new Hook();
        (new Plugin($this->container))->initialize();
        $map = $this->container['applicationAccessMap'];
        foreach (['show', 'save', 'saveSecurity', 'delete', 'setDefault', 'testConnection'] as $action) {
            $this->assertSame([Role::APP_ADMIN], $map->getRoles('AiConnectorSettingsController', $action), $action);
        }
    }
}
