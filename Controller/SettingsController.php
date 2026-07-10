<?php

namespace Kanboard\Plugin\AiConnector\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AiConnector settings — admin-only CRUD over provider profiles.
 *
 * Keys are stored separately (aiconnector_key_<id>), never echoed, and never
 * overwritten by an empty/placeholder submission (KEY_PLACEHOLDER pattern).
 *
 * @package Kanboard\Plugin\AiConnector\Controller
 * @author  Carmelo Santana
 */
class SettingsController extends BaseController
{
    public function show(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $registry = new ProviderRegistry($this->container);
        $editId   = $this->request->getStringParam('edit', '');
        $editProfile = $editId !== '' ? $registry->findProfile($editId) : null;

        $this->response->html($this->helper->layout->config('AiConnector:config/settings', [
            'title'          => t('Settings') . ' &gt; ' . t('AI Connector'),
            'profiles'       => $registry->getProfiles(),
            'default_id'     => $registry->getDefaultProfileId(),
            'providers'      => ProviderRegistry::PROVIDERS,
            'default_models' => ProviderRegistry::DEFAULT_MODELS,
            'edit_profile'   => $editProfile,
            'edit_has_key'   => $editProfile !== null ? $registry->hasStoredKey($editProfile['id']) : false,
            // Reusable CSRF token for the (external) Test-Connection fetch (Task 4).
            // MUST be generated here — `token` is not a template helper.
            'ai_test_csrf'   => $this->token->getReusableCSRFToken(),
        ]));
    }

    public function save(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $values   = $this->request->getValues();
        $registry = new ProviderRegistry($this->container);

        $provider = (string) ($values['provider'] ?? '');
        if (! array_key_exists($provider, ProviderRegistry::PROVIDERS)) {
            $this->flash->failure(t('Unsupported provider type.'));
            $this->redirectToShow();
            return;
        }

        $label   = trim((string) ($values['label'] ?? ''));
        $model   = trim((string) ($values['model'] ?? ''));
        $baseUrl = trim((string) ($values['base_url'] ?? ''));
        if ($model === '') {
            $model = ProviderRegistry::DEFAULT_MODELS[$provider] ?? '';
        }
        if ($label === '') {
            $label = ProviderRegistry::PROVIDERS[$provider];
        }

        $id = trim((string) ($values['profile_id'] ?? ''));
        $profiles = $registry->getProfiles();
        $isNew = ($id === '' || $registry->findProfile($id) === null);
        if ($isNew) {
            $id = $this->mintId($label, count($profiles));
        }

        $struct = ['id' => $id, 'label' => $label, 'provider' => $provider, 'model' => $model, 'base_url' => $baseUrl];

        // Upsert into the profiles list.
        $replaced = false;
        foreach ($profiles as $i => $p) {
            if ($p['id'] === $id) {
                $profiles[$i] = $struct;
                $replaced = true;
                break;
            }
        }
        if (! $replaced) {
            $profiles[] = $struct;
        }

        $this->configModel->save([ProviderRegistry::PROFILES_KEY => json_encode(array_values($profiles))]);
        // ConfigModel::get() reads through a memoryCache proxy that is NOT
        // invalidated by save() — flush so subsequent reads (incl. below and
        // any within this same request) see the write we just made.
        $this->memoryCache->flush();

        // Key: only persist a real (non-placeholder, non-empty) submission.
        $submitted = trim((string) ($values['api_key'] ?? ''));
        if ($submitted !== '' && $submitted !== ProviderRegistry::KEY_PLACEHOLDER) {
            $this->configModel->save([ProviderRegistry::KEY_PREFIX . $id => $submitted]);
            $this->memoryCache->flush();
        }

        // First profile becomes the default.
        if ($registry->getDefaultProfileId() === '') {
            $this->configModel->save([ProviderRegistry::DEFAULT_KEY => $id]);
            $this->memoryCache->flush();
        }

        $this->flash->success(t('Profile saved.'));
        $this->redirectToShow();
    }

    public function delete(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $id = $this->request->getStringParam('profile_id', '');
        $registry = new ProviderRegistry($this->container);
        $profiles = array_values(array_filter(
            $registry->getProfiles(),
            fn (array $p) => $p['id'] !== $id
        ));

        $this->configModel->save([ProviderRegistry::PROFILES_KEY => json_encode($profiles)]);
        $this->configModel->save([ProviderRegistry::KEY_PREFIX . $id => '']);
        // See save(): memoryCache is not invalidated by configModel->save().
        $this->memoryCache->flush();

        // Fix up the default if we removed it.
        if ($this->configModel->get(ProviderRegistry::DEFAULT_KEY, '') === $id) {
            $newDefault = $profiles[0]['id'] ?? '';
            $this->configModel->save([ProviderRegistry::DEFAULT_KEY => $newDefault]);
            $this->memoryCache->flush();
        }

        $this->flash->success(t('Profile removed.'));
        $this->redirectToShow();
    }

    public function setDefault(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $id = $this->request->getStringParam('profile_id', '');
        $registry = new ProviderRegistry($this->container);
        if ($registry->findProfile($id) !== null) {
            $this->configModel->save([ProviderRegistry::DEFAULT_KEY => $id]);
            // See save(): memoryCache is not invalidated by configModel->save().
            $this->memoryCache->flush();
            $this->flash->success(t('Default profile updated.'));
        } else {
            $this->flash->failure(t('Unknown profile.'));
        }
        $this->redirectToShow();
    }

    /**
     * Test a profile's connection: build its provider and make a minimal
     * structured() call. Admin + reusable-CSRF gated. Returns {ok} / {ok,error}
     * — never the key or the raw model output.
     */
    public function testConnection(): void
    {
        if (! $this->userSession->isAdmin()) {
            $this->response->json(['ok' => false, 'error' => t('Access denied.')]);
            return;
        }

        // The Test Connection button fetches via GET with csrf_token in the query
        // string — checkReusableGETCSRFParam() validates the reusable token from
        // $_GET (checkReusableCSRFParam() reads $_POST only, so it would 403).
        $this->checkReusableGETCSRFParam();

        $profileId = $this->request->getStringParam('profile', '');

        try {
            $registry = new ProviderRegistry($this->container);
            $schema = json_encode([
                'name'   => 'test_output',
                'schema' => [
                    'type'       => 'object',
                    'properties' => ['ok' => ['type' => 'boolean']],
                    'required'   => ['ok'],
                ],
            ]);
            $registry->structured(
                [['role' => 'user', 'content' => 'Reply with ok=true to confirm the connection works.']],
                $schema,
                $profileId !== '' ? $profileId : null
            );
            $this->response->json(['ok' => true]);
        } catch (\Throwable $e) {
            // Message never contains the key (buildProvider guarantees this).
            $this->response->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Mint a unique profile id from the label + current count + microtime. */
    private function mintId(string $label, int $count): string
    {
        return 'p_' . substr(hash('sha256', $label . '|' . $count . '|' . microtime()), 0, 8);
    }

    private function redirectToShow(): void
    {
        $this->response->redirect($this->helper->url->to('SettingsController', 'show', ['plugin' => 'AiConnector']));
    }
}
