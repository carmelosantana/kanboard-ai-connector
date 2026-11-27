<?php

namespace Kanboard\Plugin\AiConnector\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\AiConnector\Model\ModelRouting;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * The project "AI" page. Project managers (AccessMap: Role::PROJECT_MANAGER) choose one AI
 * profile for the whole project, from the profiles an admin made available, and whether members
 * may switch models with the shared picker. Features an admin locked ignore both.
 */
class AiConnectorProjectController extends BaseController
{
    public function show(array $values = [], array $errors = []): void
    {
        $project = $this->getProject();
        $registry = new ProviderRegistry($this->container);
        $routing = new ModelRouting($this->container);
        $locked = [];
        $features = $registry->features();
        foreach ($routing->assignments()['locked'] as $id) {
            if (isset($features[$id])) {
                $locked[] = $features[$id];
            }
        }

        $this->response->html($this->helper->layout->project('AiConnector:project/ai', [
            'project' => $project,
            'title' => t('AI'),
            'values' => $values + [
                'ai_profile' => $routing->projectProfile((int) $project['id']),
                'members_switch' => $routing->membersMaySwitch((int) $project['id']),
            ],
            'errors' => $errors,
            'profiles' => $registry->offeredProfiles(),
            'locked' => $locked,
        ]));
    }

    public function saveProject(): void
    {
        $project = $this->getProject();
        $this->checkCSRFForm();

        $values = $this->request->getValues();
        $profile = is_string($values['ai_profile'] ?? null) ? $values['ai_profile'] : '';
        $switch = ($values['members_switch'] ?? '') === '1';
        $offered = array_column((new ProviderRegistry($this->container))->offeredProfiles(), 'id');

        $errors = (new ModelRouting($this->container))->saveProject((int) $project['id'], $profile, $switch, $offered);
        if ($errors !== []) {
            $this->flash->failure(t('AI settings not saved.') . ' ' . implode(' ', $errors));
        } else {
            $this->flash->success(t('AI settings saved.'));
        }
        $this->response->redirect($this->helper->url->to('AiConnectorProjectController', 'show', ['project_id' => $project['id'], 'plugin' => 'AiConnector']));
    }
}
