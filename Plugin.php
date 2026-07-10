<?php

namespace Kanboard\Plugin\AiConnector;

use Kanboard\Core\Plugin\Base;

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
        $this->route->addRoute('ai-connector/settings', 'SettingsController', 'show');
        $this->route->addRoute('ai-connector/save',     'SettingsController', 'save');
        $this->route->addRoute('ai-connector/delete',   'SettingsController', 'delete');
        $this->route->addRoute('ai-connector/default',  'SettingsController', 'setDefault');
        $this->route->addRoute('ai-connector/test',     'SettingsController', 'testConnection');
    }

    public function getPluginName(): string
    {
        return 'AiConnector';
    }

    public function getPluginDescription(): string
    {
        return t('Universal multi-provider AI backend (php-agents) with provider profiles and a shared PHP API.');
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
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
