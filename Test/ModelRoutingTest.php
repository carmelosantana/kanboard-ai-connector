<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ModelRouting;
use KanboardTests\units\Base;

/** Routing storage: admin assignments in settings, the project profile and switch in project metadata. */
class ModelRoutingTest extends Base
{
    private const FEATURES = [
        'kensho.clarify' => ['id' => 'kensho.clarify', 'label' => 'Clarify', 'plugin' => 'Kensho'],
        'timereport.summary' => ['id' => 'timereport.summary', 'label' => 'Summary', 'plugin' => 'TimeReport'],
    ];
    private const PROFILES = ['a', 'b'];

    private function routing(): ModelRouting
    {
        return new ModelRouting($this->container);
    }

    private function seedConfig(string $value): void
    {
        $db = $this->container['db'];
        $db->table('settings')->eq('option', ModelRouting::CONFIG_KEY)->remove();
        $db->table('settings')->insert(['option' => ModelRouting::CONFIG_KEY, 'value' => $value]);
        $this->container['memoryCache']->flush();
    }

    private function project(): int
    {
        return (int) $this->container['projectModel']->create(['name' => 'Routing']);
    }

    public function testAssignmentsDefaultWhenMissingOrMalformed(): void
    {
        $empty = ['plugins' => [], 'features' => [], 'locked' => []];
        $this->assertSame($empty, $this->routing()->assignments());

        $this->seedConfig('not json');
        $this->assertSame($empty, $this->routing()->assignments());

        $this->seedConfig(json_encode(['plugins' => 'x', 'features' => [1, 2], 'locked' => 'y']));
        $this->assertSame($empty, $this->routing()->assignments());
    }

    public function testAssignmentsKeepOnlyStringMapsAndAStringList(): void
    {
        $this->seedConfig(json_encode([
            'plugins' => ['Kensho' => 'a', 'Bad' => 5],
            'features' => ['kensho.clarify' => 'b', 'x' => ['nested']],
            'locked' => ['kensho.clarify', 7],
        ]));

        $this->assertSame([
            'plugins' => ['Kensho' => 'a'],
            'features' => ['kensho.clarify' => 'b'],
            'locked' => ['kensho.clarify'],
        ], $this->routing()->assignments());
    }

    public function testAValidPostIsSavedAndReadBack(): void
    {
        $errors = $this->routing()->saveAssignments([
            'plugins' => ['Kensho' => 'a', 'TimeReport' => ''],
            'features' => ['kensho.clarify' => 'b', 'timereport.summary' => ''],
            'locked' => ['kensho.clarify'],
        ], self::FEATURES, self::PROFILES);

        $this->assertSame([], $errors);
        $this->container['memoryCache']->flush();
        $this->assertSame([
            'plugins' => ['Kensho' => 'a'],
            'features' => ['kensho.clarify' => 'b'],
            'locked' => ['kensho.clarify'],
        ], $this->routing()->assignments());
    }

    /** @dataProvider badPosts */
    public function testABadPostSavesNothing(array $post, string $errorKey): void
    {
        $this->routing()->saveAssignments(['plugins' => ['Kensho' => 'a']], self::FEATURES, self::PROFILES);

        $errors = $this->routing()->saveAssignments($post, self::FEATURES, self::PROFILES);

        $this->assertArrayHasKey($errorKey, $errors);
        $this->container['memoryCache']->flush();
        $this->assertSame(['Kensho' => 'a'], $this->routing()->assignments()['plugins']);
    }

    public static function badPosts(): array
    {
        return [
            'unknown profile on a plugin' => [['plugins' => ['Kensho' => 'ghost']], 'plugins.Kensho'],
            'unknown profile on a feature' => [['features' => ['kensho.clarify' => 'ghost']], 'features.kensho.clarify'],
            'unregistered feature' => [['features' => ['nope.x' => 'a']], 'features.nope.x'],
            'unregistered plugin' => [['plugins' => ['Nope' => 'a']], 'plugins.Nope'],
            'locked feature not registered' => [['locked' => ['nope.x']], 'locked.nope.x'],
            'non-string value' => [['plugins' => ['Kensho' => ['a']]], 'plugins.Kensho'],
            'good and bad together' => [['plugins' => ['TimeReport' => 'b', 'Kensho' => 'ghost']], 'plugins.Kensho'],
        ];
    }

    public function testValidateReturnsTheCleanValuesWithoutSaving(): void
    {
        [$errors, $clean] = $this->routing()->validate(['features' => ['kensho.clarify' => 'a']], self::FEATURES, self::PROFILES);

        $this->assertSame([], $errors);
        $this->assertSame(['plugins' => [], 'features' => ['kensho.clarify' => 'a'], 'locked' => []], $clean);
        $this->assertSame([], $this->routing()->assignments()['features']);
    }

    public function testProjectDefaults(): void
    {
        $pid = $this->project();
        $this->assertSame('', $this->routing()->projectProfile($pid));
        $this->assertFalse($this->routing()->membersMaySwitch($pid));
    }

    public function testSaveProjectStoresBothKeys(): void
    {
        $pid = $this->project();

        $this->assertSame([], $this->routing()->saveProject($pid, 'b', true, self::PROFILES));
        $this->assertSame('b', $this->routing()->projectProfile($pid));
        $this->assertTrue($this->routing()->membersMaySwitch($pid));
        $this->assertSame('1', $this->container['projectMetadataModel']->get($pid, ModelRouting::MEMBERS_SWITCH));

        $this->assertSame([], $this->routing()->saveProject($pid, '', false, self::PROFILES));
        $this->assertSame('', $this->routing()->projectProfile($pid));
        $this->assertFalse($this->routing()->membersMaySwitch($pid));
        // MetadataModel::get() turns a stored '0' into its default, so read the row itself.
        $this->assertSame('0', (string) $this->container['db']->table('project_has_metadata')
            ->eq('project_id', $pid)->eq('name', ModelRouting::MEMBERS_SWITCH)->findOneColumn('value'));
    }

    public function testSaveProjectRefusesAProfileThatIsNotOffered(): void
    {
        $pid = $this->project();
        $this->routing()->saveProject($pid, 'a', false, self::PROFILES);

        $errors = $this->routing()->saveProject($pid, 'ghost', true, self::PROFILES);

        $this->assertArrayHasKey(ModelRouting::PROJECT_PROFILE, $errors);
        $this->assertSame('a', $this->routing()->projectProfile($pid));
        $this->assertFalse($this->routing()->membersMaySwitch($pid));
    }
}
