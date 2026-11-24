<?php

namespace Kanboard\Plugin\AiConnector\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Model\UnsafeUrlException;
use Kanboard\Plugin\AiConnector\Model\UrlGuard;

/**
 * AiConnector settings — admin-only CRUD over provider profiles.
 *
 * Keys are stored separately (aiconnector_key_<id>), never echoed, and never
 * overwritten by an empty/placeholder submission (KEY_PLACEHOLDER pattern).
 *
 * @package Kanboard\Plugin\AiConnector\Controller
 * @author  Carmelo Santana
 */
class AiConnectorSettingsController extends BaseController
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
            'allowed_hosts'  => implode("\n", UrlGuard::parseHostList((string) $this->configModel->get(UrlGuard::ALLOWED_HOSTS_KEY, ''))),
            'allow_private'  => (string) $this->configModel->get(UrlGuard::ALLOW_PRIVATE_KEY, '') === '1',
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
        if (! ProviderRegistry::isValidBaseUrl($baseUrl)) {
            $this->flash->failure(t('The base URL must be an http:// or https:// address with a host.'));
            $this->redirectToShow();
            return;
        }
        // SSRF guard: the URL this profile's requests would go to must pass the
        // allow-list and private-network rules (checked after DNS resolution).
        if (in_array($provider, ProviderRegistry::BASE_URL_PROVIDERS, true)) {
            try {
                UrlGuard::fromConfig($this->configModel)->check($registry->effectiveBaseUrl($provider, $baseUrl));
            } catch (UnsafeUrlException $e) {
                $this->flash->failure(t('Profile not saved.') . ' ' . $e->getMessage());
                $this->redirectToShow();
                return;
            }
        }
        if ($model === '') {
            $model = ProviderRegistry::DEFAULT_MODELS[$provider] ?? '';
        }
        if ($label === '') {
            $label = ProviderRegistry::PROVIDERS[$provider];
        }

        $id = trim((string) ($values['profile_id'] ?? ''));
        $profiles = $registry->getProfiles();
        $previous = $id !== '' ? $registry->findProfile($id) : null;
        $isNew = $previous === null;
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
        // A stored key belongs to the endpoint it was entered for: when the
        // provider or base URL host changes, it must be entered again.
        $submitted = trim((string) ($values['api_key'] ?? ''));
        $keyCleared = false;
        if ($submitted !== '' && $submitted !== ProviderRegistry::KEY_PLACEHOLDER) {
            $this->configModel->save([ProviderRegistry::KEY_PREFIX . $id => $submitted]);
            $this->memoryCache->flush();
        } elseif ($previous !== null
            && ProviderRegistry::endpoint($previous['provider'], $previous['base_url']) !== ProviderRegistry::endpoint($provider, $baseUrl)
            && $registry->hasStoredKey($id)) {
            $this->configModel->save([ProviderRegistry::KEY_PREFIX . $id => '']);
            $this->memoryCache->flush();
            $keyCleared = true;
        }

        // First profile becomes the default.
        if ($registry->getDefaultProfileId() === '') {
            $this->configModel->save([ProviderRegistry::DEFAULT_KEY => $id]);
            $this->memoryCache->flush();
        }

        $this->flash->success($keyCleared
            ? t('Profile saved.') . ' ' . t('The provider or base URL changed, so the stored API key was cleared. Enter the API key again.')
            : t('Profile saved.'));
        $this->redirectToShow();
    }

    /** Save the network security settings: host allow-list + LAN opt-in. */
    public function saveSecurity(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $values = $this->request->getValues();
        $hosts  = UrlGuard::parseHostList((string) ($values['allowed_hosts'] ?? ''));
        $invalid = array_values(array_filter($hosts, fn (string $h) => ! UrlGuard::isValidHostEntry($h)));
        if ($invalid !== []) {
            $this->flash->failure(t('Not a host name or IP address: %s', implode(', ', $invalid)));
            $this->redirectToShow();
            return;
        }

        $this->configModel->save([
            UrlGuard::ALLOWED_HOSTS_KEY => implode("\n", $hosts),
            UrlGuard::ALLOW_PRIVATE_KEY => ! empty($values['allow_private']) ? '1' : '0',
        ]);
        $this->memoryCache->flush();

        $this->flash->success(t('Network security settings saved.'));
        $this->redirectToShow();
    }

    public function delete(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        // The forms POST profile_id in the body; getStringParam() only reads the query string.
        $id = trim((string) ($this->request->getValues()['profile_id'] ?? ''));
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

        // The forms POST profile_id in the body; getStringParam() only reads the query string.
        $id = trim((string) ($this->request->getValues()['profile_id'] ?? ''));
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
        } catch (UnsafeUrlException $e) {
            // The guard's own message names only the host and the reason.
            $this->logger->error(sprintf('[AiConnector] Test connection refused by the URL guard: %s', $e->reason));
            $this->response->json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Provider errors can carry upstream response text: log the class
            // and code only, and give the browser a generic message.
            $this->logger->error(sprintf('[AiConnector] Test connection failed: %s (code %s)', get_class($e), (string) $e->getCode()));
            $this->response->json(['ok' => false, 'error' => t('Connection failed. Check the profile settings; details are in the server log.')]);
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
        $this->response->redirect($this->helper->url->to('AiConnectorSettingsController', 'show', ['plugin' => 'AiConnector']));
    }
}
