<?php

namespace Kanboard\Plugin\AiConnector\Model;

/**
 * Model routing storage. Admin assignments (a profile per plugin and per feature, and the
 * locked features) live in the `aiconnector_routing` setting as JSON; each project's profile
 * and its "members may switch" choice live in project metadata.
 *
 * Saves are all-or-nothing: every value is checked first, and nothing is stored when any
 * value is refused. Resolution (which profile a request uses) is ProviderRegistry::resolve().
 */
final class ModelRouting
{
    public const CONFIG_KEY = 'aiconnector_routing';
    public const PROJECT_PROFILE = 'aiconnector_project_profile';
    public const MEMBERS_SWITCH = 'aiconnector_members_switch';

    public function __construct(private $container)
    {
    }

    /** @return array{plugins: array<string,string>, features: array<string,string>, locked: list<string>} */
    public function assignments(): array
    {
        $decoded = json_decode((string) $this->container['configModel']->get(self::CONFIG_KEY, ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        return [
            'plugins' => $this->stringMap($decoded['plugins'] ?? null),
            'features' => $this->stringMap($decoded['features'] ?? null),
            'locked' => $this->stringList($decoded['locked'] ?? null),
        ];
    }

    /**
     * Checks a posted routing form without saving it. Empty profile values mean "unset".
     *
     * @param array<string, array{id:string,label:string,plugin:string}> $features registered features
     * @param list<string> $profileIds every existing profile id
     * @return array{0: array<string,string>, 1: array{plugins: array<string,string>, features: array<string,string>, locked: list<string>}}
     *         errors by key ("plugins.<name>", "features.<id>", "locked.<id>") and the clean values
     */
    public function validate(array $posted, array $features, array $profileIds): array
    {
        $plugins = array_flip(array_column($features, 'plugin'));
        $errors = [];
        $clean = ['plugins' => [], 'features' => [], 'locked' => []];

        foreach (['plugins' => $plugins, 'features' => $features] as $level => $known) {
            $values = $posted[$level] ?? [];
            foreach (is_array($values) ? $values : [] as $key => $profile) {
                $key = (string) $key;
                if (! isset($known[$key])) {
                    $errors["$level.$key"] = t('%s is not a registered AI feature or plugin.', $key);
                    continue;
                }
                if (! is_string($profile)) {
                    $errors["$level.$key"] = t('Choose a profile for %s.', $key);
                    continue;
                }
                if ($profile === '') {
                    continue;
                }
                if (! in_array($profile, $profileIds, true)) {
                    $errors["$level.$key"] = t('The profile chosen for %s no longer exists.', $key);
                    continue;
                }
                $clean[$level][$key] = $profile;
            }
        }

        $locked = $posted['locked'] ?? [];
        foreach (is_array($locked) ? $locked : [] as $id) {
            $id = is_string($id) ? $id : '';
            if (! isset($features[$id])) {
                $errors["locked.$id"] = t('%s is not a registered AI feature.', $id);
                continue;
            }
            $clean['locked'][] = $id;
        }
        $clean['locked'] = array_values(array_unique($clean['locked']));

        return [$errors, $clean];
    }

    /**
     * Validates and, when every value is accepted, saves the admin assignments.
     *
     * @return array<string,string> errors by key, empty when saved
     */
    public function saveAssignments(array $posted, array $features, array $profileIds): array
    {
        [$errors, $clean] = $this->validate($posted, $features, $profileIds);
        if ($errors === []) {
            $this->container['configModel']->save([self::CONFIG_KEY => json_encode($clean)]);
            $this->container['memoryCache']->flush();
        }
        return $errors;
    }

    /** The project's profile id, or '' for "use the suite's settings". */
    public function projectProfile(int $projectId): string
    {
        return (string) $this->container['projectMetadataModel']->get($projectId, self::PROJECT_PROFILE, '');
    }

    public function membersMaySwitch(int $projectId): bool
    {
        return (string) $this->container['projectMetadataModel']->get($projectId, self::MEMBERS_SWITCH, '0') === '1';
    }

    /**
     * Saves the project's profile and switch when the profile is '' or one of $offeredIds.
     *
     * @param list<string> $offeredIds the profiles project managers may choose
     * @return array<string,string> errors by key, empty when saved
     */
    public function saveProject(int $projectId, string $profile, bool $membersMaySwitch, array $offeredIds): array
    {
        if ($profile !== '' && ! in_array($profile, $offeredIds, true)) {
            return [self::PROJECT_PROFILE => t('Choose one of the offered AI profiles.')];
        }
        $this->container['projectMetadataModel']->save($projectId, [
            self::PROJECT_PROFILE => $profile,
            self::MEMBERS_SWITCH => $membersMaySwitch ? '1' : '0',
        ]);
        return [];
    }

    /** @return array<string,string> */
    private function stringMap(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $k => $v) {
            if (is_string($k) && is_string($v) && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_string'));
    }
}
