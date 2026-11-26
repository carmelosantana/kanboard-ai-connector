<?php

namespace Kanboard\Plugin\AiConnector;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;

/**
 * AiConnector — universal multi-provider AI backend for the Kanboard suite.
 *
 * Owns php-agents (bundled in vendor/) and all provider configuration; exposes
 * the ProviderRegistry PHP API that other plugins consume. See
 * docs/superpowers/specs/2026-07-10-aiconnector-design.md.
 *
 * php-agents load-order rule (HARD): this initialize() require_once's the
 * plugin-local vendor/autoload.php. No CarmeloSantana\PHPAgents\* class is ever
 * referenced here at init time — only at request-handling time in the
 * controller/model, because Kanboard gives no cross-plugin init-order guarantee.
 */
class Plugin extends Base
{
    public function initialize(): void
    {
        // Load the plugin-local vendor autoload (php-agents + deps).
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        } else {
            error_log('[AiConnector] vendor/autoload.php not found — run composer install inside the plugin directory.');
        }

        // ── Settings sidebar link ─────────────────────────────────────────────
        $this->hook->on('template:config:sidebar', [
            'template' => 'AiConnector:config/sidebar',
        ]);

        // ── External JS (CSP-safe: Test Connection + provider→model auto-fill) ─
        $this->hook->on('template:layout:js', [
            'template' => 'plugins/AiConnector/Assets/js/ai-connector.js',
        ]);

        // ── Settings routes ───────────────────────────────────────────────────
        $this->route->addRoute('ai-connector/settings', 'AiConnectorSettingsController', 'show');
        $this->route->addRoute('ai-connector/save',     'AiConnectorSettingsController', 'save');
        $this->route->addRoute('ai-connector/security', 'AiConnectorSettingsController', 'saveSecurity');
        $this->route->addRoute('ai-connector/limits',   'AiConnectorSettingsController', 'saveLimits');
        $this->route->addRoute('ai-connector/delete',   'AiConnectorSettingsController', 'delete');
        $this->route->addRoute('ai-connector/default',  'AiConnectorSettingsController', 'setDefault');
        $this->route->addRoute('ai-connector/test',     'AiConnectorSettingsController', 'testConnection');
        // Its own rule: the old shared name SettingsController took core's admin rule; this one would fall to the app-user default (#7337).
        $this->applicationAccessMap->add('AiConnectorSettingsController', '*', Role::APP_ADMIN);
    }

    public function getPluginName(): string
    {
        return 'AiConnector';
    }

    public function getPluginDescription(): string
    {
        return t('Universal multi-provider AI backend (php-agents) with provider profiles, model context-window discovery, and a shared PHP API.');
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.3.0';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-ai-connector';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
