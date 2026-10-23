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
        $json = json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
        $this->assertSame($json['version'], $plugin->getPluginVersion(), 'getPluginVersion() must match plugin.json version');
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

    public function testVendoredPhpAgentsMatchesLockAndCarriesTheGeminiFix(): void
    {
        $root = dirname(__DIR__);
        $find = static function (array $packages): ?string {
            foreach ($packages as $package) {
                if ($package['name'] === 'carmelosantana/php-agents') {
                    return ltrim($package['version'], 'v');
                }
            }
            return null;
        };
        $locked = $find(json_decode(file_get_contents($root . '/composer.lock'), true)['packages']);
        $installed = $find(json_decode(file_get_contents($root . '/vendor/composer/installed.json'), true)['packages']);

        $this->assertSame($locked, $installed, 'vendor/ must be re-vendored from composer.lock');
        // 0.16.1 keeps a Gemini structured() properties map an object (Kanboard #4556).
        $this->assertTrue(version_compare($installed, '0.16.1', '>='), "php-agents $installed predates 0.16.1");
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
