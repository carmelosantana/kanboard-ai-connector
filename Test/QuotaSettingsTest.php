<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AiConnector\Controller\AiConnectorSettingsController;
use Kanboard\Plugin\AiConnector\Model\AiQuota;
use KanboardTests\units\Base;

/** The AI limits form on the AiConnector settings page: admin only, CSRF checked, all-or-nothing. */
class QuotaSettingsTest extends Base
{
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

    private function driveSaveLimits(): void
    {
        try {
            (new AiConnectorSettingsController($this->container))->saveLimits();
        } catch (\Throwable $e) {
            if ($e instanceof AccessForbiddenException) {
                throw $e;
            }
            // redirect() exits/throws in test context — expected.
        }
    }

    private function stored(): array
    {
        $this->container['memoryCache']->flush();
        return (new AiQuota($this->container))->values();
    }

    public function testNonAdminIsRefused(): void
    {
        $this->stubSession(false, [AiQuota::REQUESTS_PER_HOUR => '5', AiQuota::MAX_INPUT_CHARS => '100']);
        $this->expectException(AccessForbiddenException::class);
        $this->driveSaveLimits();
    }

    public function testBadCsrfIsRefused(): void
    {
        $this->stubSession(true, [AiQuota::REQUESTS_PER_HOUR => '5', AiQuota::MAX_INPUT_CHARS => '100'], false);
        $this->expectException(AccessForbiddenException::class);
        $this->driveSaveLimits();
    }

    public function testAdminSavesBothLimits(): void
    {
        $this->stubSession(true, [AiQuota::REQUESTS_PER_HOUR => '5', AiQuota::MAX_INPUT_CHARS => '100']);
        $this->driveSaveLimits();
        $this->assertSame([AiQuota::REQUESTS_PER_HOUR => 5, AiQuota::MAX_INPUT_CHARS => 100], $this->stored());
    }

    public function testABadValueSavesNothing(): void
    {
        $this->stubSession(true, [AiQuota::REQUESTS_PER_HOUR => '5', AiQuota::MAX_INPUT_CHARS => '0']);
        $this->driveSaveLimits();
        $this->assertSame([AiQuota::REQUESTS_PER_HOUR => 30, AiQuota::MAX_INPUT_CHARS => 32000], $this->stored());
    }

    public function testTheRouteIsRegisteredAndTheTemplatePostsEveryField(): void
    {
        $this->assertStringContainsString("'ai-connector/limits'", file_get_contents(dirname(__DIR__).'/Plugin.php'));
        $template = file_get_contents(dirname(__DIR__).'/Template/config/settings.php');
        $this->assertStringContainsString("'saveLimits'", $template);
        $this->assertStringContainsString('AiQuota::FIELDS', $template);
    }
}
