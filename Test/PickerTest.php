<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ModelRouting;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/** pickerOptions() and the shared AiConnector:picker/select partial. */
class PickerTest extends Base
{
    private int $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profiles([
            ['id' => 'A', 'label' => 'Alpha', 'provider' => 'ollama', 'model' => 'm', 'base_url' => 'http://secret.example/v1'],
            ['id' => 'B', 'label' => 'Beta', 'provider' => 'ollama', 'model' => 'm2', 'base_url' => '', 'available' => true],
            ['id' => 'C', 'label' => 'Gamma', 'provider' => 'ollama', 'model' => 'm3', 'base_url' => ''],
        ]);
        $this->setting(ProviderRegistry::DEFAULT_KEY, 'A');
        $this->container['hook']->on(ProviderRegistry::FEATURES_HOOK, fn () => [['id' => 't.one', 'label' => 'One', 'plugin' => 'T']]);
        $this->project = (int) $this->container['projectModel']->create(['name' => 'Picker']);
    }

    private function setting(string $option, string $value): void
    {
        $db = $this->container['db'];
        $db->table('settings')->eq('option', $option)->remove();
        $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        $this->container['memoryCache']->flush();
    }

    private function profiles(array $profiles): void
    {
        $this->setting(ProviderRegistry::PROFILES_KEY, json_encode($profiles));
    }

    private function options(?int $project = null): array
    {
        return (new ProviderRegistry($this->container))->pickerOptions('t.one', $project);
    }

    private function render(array $options, array $extra = []): string
    {
        return $this->container['template']->render('AiConnector:picker/select', ['options' => $options] + $extra);
    }

    public function testOffersAvailableProfilesPlusTheDefaultWithoutSecrets(): void
    {
        $options = $this->options();

        $this->assertSame([
            ['id' => 'A', 'label' => 'Alpha', 'provider' => 'ollama', 'model' => 'm'],
            ['id' => 'B', 'label' => 'Beta', 'provider' => 'ollama', 'model' => 'm2'],
        ], $options['profiles']);
        $this->assertTrue($options['show']);
        $this->assertSame('A', $options['selected']);
    }

    public function testHiddenWhenFewerThanTwoAreOffered(): void
    {
        $this->profiles([['id' => 'A', 'label' => 'Alpha', 'provider' => 'ollama', 'model' => 'm', 'base_url' => '']]);
        $this->assertFalse($this->options()['show']);
    }

    public function testHiddenWhenLocked(): void
    {
        $this->setting(ModelRouting::CONFIG_KEY, json_encode(['features' => ['t.one' => 'B'], 'locked' => ['t.one']]));
        $options = $this->options();
        $this->assertFalse($options['show']);
        $this->assertSame('B', $options['selected']);
    }

    public function testInAProjectItFollowsTheMembersSwitch(): void
    {
        $this->assertFalse($this->options($this->project)['show']);

        $this->container['projectMetadataModel']->save($this->project, [ModelRouting::PROJECT_PROFILE => 'B', ModelRouting::MEMBERS_SWITCH => '1']);
        $options = $this->options($this->project);
        $this->assertTrue($options['show']);
        $this->assertSame('B', $options['selected']);
    }

    public function testTemplateRendersNothingWhenHidden(): void
    {
        $this->assertSame('', trim($this->render(['show' => false, 'profiles' => [], 'selected' => ''])));
    }

    public function testTemplateRendersTheSelectWithTheSelectedOption(): void
    {
        $html = $this->render($this->options());

        $this->assertStringContainsString('name="ai_profile"', $html);
        $this->assertMatchesRegularExpression('/<option value="A"\s+selected/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="B"\s+selected/', $html);
        $this->assertStringNotContainsString('secret.example', $html);
    }

    public function testTemplateHonoursACustomNameAndEscapesLabels(): void
    {
        $html = $this->render([
            'show' => true,
            'profiles' => [['id' => 'A', 'label' => '<b>x</b>', 'provider' => 'ollama', 'model' => 'm'], ['id' => 'B', 'label' => 'B', 'provider' => 'ollama', 'model' => 'm']],
            'selected' => 'B',
        ], ['name' => 'other_field']);

        $this->assertStringContainsString('name="other_field"', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }
}
