<?php

require_once 'tests/units/Base.php';

use CarmeloSantana\PHPAgents\Agent\RunLimits;
use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/**
 * The bundled php-agents is 0.21+ (RunLimits, typed stream events, UsageListener, structured streaming, status-only HTTP errors) and the Anthropic default is Sonnet 5.5.
 */
class PhpAgentsVersionTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    public function testBundlesRunLimits(): void
    {
        $this->assertTrue(class_exists(RunLimits::class));
    }

    public function testBundlesTypedStreamEventsAndUsageListener(): void
    {
        $this->assertTrue(class_exists(Tick::class));
        $this->assertTrue(interface_exists(UsageListener::class));
    }

    public function testBundlesStructuredStreamingAndStatusOnlyErrors(): void
    {
        $this->assertTrue(interface_exists(\CarmeloSantana\PHPAgents\Contract\StructuredStreaming::class));
        $this->assertTrue(class_exists(\CarmeloSantana\PHPAgents\Provider\Stream\StructuredDelta::class));
        $this->assertTrue(class_exists(\CarmeloSantana\PHPAgents\Exception\HttpStatusException::class));
    }

    public function testDefaultAnthropicModel(): void
    {
        $this->assertSame('claude-sonnet-5-5', ProviderRegistry::DEFAULT_MODELS[ProviderRegistry::PROVIDER_ANTHROPIC]);
    }
}
