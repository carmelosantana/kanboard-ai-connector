<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Plugin;
use KanboardTests\units\Base;

/**
 * Smoke tests for the AiConnector Plugin.
 *
 * Run from the repo root:
 *   ./testing/run-plugin-tests.sh AiConnector
 */
class PluginTest extends Base
{
    public function testPluginMetadata(): void
    {
        $plugin = new Plugin($this->container);

        $this->assertSame('AiConnector', $plugin->getPluginName());
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertSame('Carmelo Santana', $plugin->getPluginAuthor());
        $this->assertNotEmpty($plugin->getPluginHomepage());
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
    }

    public function testVendorAutoloadExists(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $this->assertFileExists(
            $autoload,
            'vendor/autoload.php must exist — run composer install inside AiConnector/'
        );
    }

    public function testProviderClassesResolve(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        foreach ([
            'AnthropicProvider',
            'OpenAICompatibleProvider',
            'OpenAIResponsesProvider',
            'XAIProvider',
            'GeminiProvider',
            'MistralProvider',
            'OllamaProvider',
        ] as $cls) {
            $this->assertTrue(
                class_exists('CarmeloSantana\\PHPAgents\\Provider\\' . $cls),
                "$cls must resolve after loading vendor/autoload.php"
            );
        }
    }
}
