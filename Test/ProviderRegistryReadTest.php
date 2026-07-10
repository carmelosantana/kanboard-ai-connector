<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/**
 * Task 1 — profile reads, key resolution, isReady. No network.
 */
class ProviderRegistryReadTest extends Base
{
    /** Write a setting directly to the DB (bypasses SettingModel::save/userSession freeze). */
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

    private function seedProfiles(array $profiles, string $default = ''): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode($profiles));
        $this->seed(ProviderRegistry::DEFAULT_KEY, $default);
    }

    private function registry(): ProviderRegistry
    {
        return new ProviderRegistry($this->container);
    }

    private function clearEnv(): void
    {
        foreach (['ANTHROPIC_API_KEY','OPENAI_API_KEY','XAI_API_KEY','GEMINI_API_KEY','MISTRAL_API_KEY'] as $v) {
            putenv($v . '=');
        }
    }

    public function testListProfilesEmptyWhenNoneConfigured(): void
    {
        $this->assertSame([], $this->registry()->listProfiles());
    }

    public function testListProfilesOmitsKeysAndBaseUrl(): void
    {
        $this->seedProfiles([
            ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514', 'base_url' => ''],
        ], 'p1');

        $list = $this->registry()->listProfiles();
        $this->assertCount(1, $list);
        $this->assertSame(['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'], $list[0]);
        $this->assertArrayNotHasKey('base_url', $list[0]);
        $this->assertArrayNotHasKey('key', $list[0]);
    }

    public function testGetDefaultProfileId(): void
    {
        $this->assertSame('', $this->registry()->getDefaultProfileId());

        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '']], 'p1');
        $this->assertSame('p1', $this->registry()->getDefaultProfileId());
    }

    public function testGetDefaultProfileIdReturnsEmptyWhenDangling(): void
    {
        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '']], 'ghost');
        $this->assertSame('', $this->registry()->getDefaultProfileId());
    }

    public function testIsReadyFalseWhenNoProfiles(): void
    {
        $this->clearEnv();
        $this->assertFalse($this->registry()->isReady());
    }

    public function testIsReadyFalseWhenProfileHasNoKeyAndNoEnv(): void
    {
        $this->clearEnv();
        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '']], 'p1');
        $this->assertFalse($this->registry()->isReady());
    }

    public function testIsReadyTrueWithStoredKey(): void
    {
        $this->clearEnv();
        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '']], 'p1');
        $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', 'sk-stored');
        $this->assertTrue($this->registry()->isReady());
    }

    public function testIsReadyTrueViaEnvFallback(): void
    {
        $this->clearEnv();
        putenv('OPENAI_API_KEY=env-key');
        $this->seedProfiles([['id' => 'p1', 'label' => 'O', 'provider' => 'openai', 'model' => 'gpt-4o', 'base_url' => '']], 'p1');
        $this->assertTrue($this->registry()->isReady());
        putenv('OPENAI_API_KEY=');
    }

    public function testIsReadyTrueForKeylessOllama(): void
    {
        $this->clearEnv();
        $this->seedProfiles([['id' => 'p1', 'label' => 'Local', 'provider' => 'ollama', 'model' => 'llama3.2', 'base_url' => '']], 'p1');
        $this->assertTrue($this->registry()->isReady());
    }

    public function testResolveKeyPrefersStoredThenEnvThenEmpty(): void
    {
        $this->clearEnv();
        $r = $this->registry();
        $this->assertSame('stored', $r->resolveKey('anthropic', 'stored'));

        putenv('ANTHROPIC_API_KEY=envk');
        $this->assertSame('envk', $r->resolveKey('anthropic', ''));
        putenv('ANTHROPIC_API_KEY=');

        $this->assertSame('', $r->resolveKey('anthropic', ''));
    }

    public function testFindProfileReturnsFullStructOrNull(): void
    {
        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'openai', 'model' => 'gpt-4o', 'base_url' => 'https://x/v1']], 'p1');
        $r = $this->registry();
        $p = $r->findProfile('p1');
        $this->assertSame('https://x/v1', $p['base_url']);
        $this->assertNull($r->findProfile('nope'));
    }

    public function testHasStoredKey(): void
    {
        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '']], 'p1');
        $r = $this->registry();

        $this->assertFalse($r->hasStoredKey('p1'));
        $this->assertFalse($r->hasStoredKey('nope'));

        $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', 'sk-stored');
        $this->assertTrue($this->registry()->hasStoredKey('p1'));
        $this->assertFalse($this->registry()->hasStoredKey('nope'));
    }

    public function testGetProfilesReturnsEmptyOnMalformedJson(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, 'NOT_JSON{{');
        $r = $this->registry();
        $this->assertSame([], $r->getProfiles());
        $this->assertSame([], $r->listProfiles());
    }

    public function testGetProfilesDropsMalformedEntries(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['label' => 'no id/provider'],
            ['id' => 'p1', 'label' => 'Good', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => ''],
        ]));
        $profiles = $this->registry()->getProfiles();
        $this->assertCount(1, $profiles);
        $this->assertSame('p1', $profiles[0]['id']);
        $this->assertSame('Good', $profiles[0]['label']);
    }
}
