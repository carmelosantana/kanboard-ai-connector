<div class="page-header">
    <h2><?= t('AI Connector — Provider Profiles') ?></h2>
</div>

<p class="form-help">
    <?= t('Configure one or more AI provider profiles. Other plugins (e.g. Subtask Generator) use these. API keys are stored securely and never displayed after saving.') ?>
</p>

<?php /* ── Existing profiles ─────────────────────────────────────────────── */ ?>
<?php if (! empty($profiles)): ?>
<table class="table-striped">
    <thead>
        <tr>
            <th><?= t('Default') ?></th>
            <th><?= t('Label') ?></th>
            <th><?= t('Provider') ?></th>
            <th><?= t('Model') ?></th>
            <th><?= t('Actions') ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($profiles as $p): ?>
        <tr>
            <td>
                <?php if ($p['id'] === $default_id): ?>
                    <strong><?= t('Default') ?></strong>
                <?php else: ?>
                    <form method="post" style="display:inline"
                          action="<?= $this->url->href('SettingsController', 'setDefault', ['plugin' => 'AiConnector']) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="profile_id" value="<?= $this->text->e($p['id']) ?>">
                        <button type="submit" class="btn btn-grey"><?= t('Make default') ?></button>
                    </form>
                <?php endif ?>
            </td>
            <td><?= $this->text->e($p['label']) ?></td>
            <td><?= $this->text->e($providers[$p['provider']] ?? $p['provider']) ?></td>
            <td><?= $this->text->e($p['model']) ?></td>
            <td>
                <?= $this->url->link(t('Edit'), 'SettingsController', 'show', ['plugin' => 'AiConnector', 'edit' => $p['id']]) ?>
                &nbsp;
                <form method="post" style="display:inline"
                      action="<?= $this->url->href('SettingsController', 'delete', ['plugin' => 'AiConnector']) ?>">
                    <?= $this->form->csrf() ?>
                    <input type="hidden" name="profile_id" value="<?= $this->text->e($p['id']) ?>">
                    <button type="submit" class="btn btn-red"><?= t('Remove') ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
<?php else: ?>
    <div class="alert alert-info"><?= t('No provider profiles yet. Add one below.') ?></div>
<?php endif ?>

<hr>

<?php /* ── Add / edit form ────────────────────────────────────────────────── */ ?>
<h3><?= $edit_profile ? t('Edit Profile') : t('Add Profile') ?></h3>

<form method="post"
      action="<?= $this->url->href('SettingsController', 'save', ['plugin' => 'AiConnector']) ?>">
    <?= $this->form->csrf() ?>
    <input type="hidden" name="profile_id" value="<?= $this->text->e($edit_profile['id'] ?? '') ?>">

    <?= $this->form->label(t('Label'), 'label') ?>
    <input type="text" name="label" id="label" class="form-text"
           value="<?= $this->text->e($edit_profile['label'] ?? '') ?>"
           placeholder="<?= t('e.g. Claude Sonnet') ?>">

    <?= $this->form->label(t('Provider'), 'provider') ?>
    <select name="provider" id="ai_provider" class="auto-select"
            data-defaults='<?= htmlspecialchars(json_encode($default_models), ENT_QUOTES) ?>'>
        <?php foreach ($providers as $key => $plabel): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                <?= (($edit_profile['provider'] ?? '') === $key) ? 'selected' : '' ?>>
                <?= htmlspecialchars($plabel, ENT_QUOTES) ?>
            </option>
        <?php endforeach ?>
    </select>

    <?= $this->form->label(t('Model'), 'model') ?>
    <input type="text" name="model" id="ai_model" class="form-text"
           value="<?= $this->text->e($edit_profile['model'] ?? '') ?>"
           placeholder="<?= htmlspecialchars($default_models[$edit_profile['provider'] ?? 'anthropic'] ?? '', ENT_QUOTES) ?>">

    <?= $this->form->label(t('Base URL (optional)'), 'base_url') ?>
    <input type="text" name="base_url" id="base_url" class="form-text"
           value="<?= $this->text->e($edit_profile['base_url'] ?? '') ?>"
           placeholder="<?= t('Leave blank for the provider default') ?>">
    <p class="form-help"><?= t('Only used for OpenAI-compatible / Ollama / self-hosted endpoints.') ?></p>

    <?= $this->form->label(t('API Key'), 'api_key') ?>
    <?php if ($edit_has_key): ?>
        <p class="form-help" style="color:green;">
            <?= t('An API key is stored. Leave blank to keep it, or enter a new key to replace it.') ?>
        </p>
    <?php endif ?>
    <input type="password" name="api_key" id="api_key" class="form-text" value=""
           placeholder="<?= t('Leave blank to keep the current key (Ollama needs none)') ?>"
           autocomplete="new-password">
    <p class="form-help">
        <?= t('Env-var fallback when blank: ANTHROPIC_API_KEY / OPENAI_API_KEY / XAI_API_KEY / GEMINI_API_KEY / MISTRAL_API_KEY. Ollama is keyless.') ?>
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= $edit_profile ? t('Save Profile') : t('Add Profile') ?></button>
        <?php if ($edit_profile): ?>
            <?= $this->url->link(t('Cancel'), 'SettingsController', 'show', ['plugin' => 'AiConnector']) ?>
        <?php endif ?>
    </div>
</form>

<hr>
<h3><?= t('Test Connection') ?></h3>
<p class="form-help"><?= t('Verify a saved profile works. Select a profile and click Test.') ?></p>

<div id="ai-test-result" style="display:none; padding:8px; border-radius:4px; margin-bottom:12px;"></div>

<?php if (! empty($profiles)): ?>
<select id="ai-test-profile" class="form-select">
    <?php foreach ($profiles as $p): ?>
        <option value="<?= $this->text->e($p['id']) ?>" <?= ($p['id'] === $default_id) ? 'selected' : '' ?>>
            <?= $this->text->e($p['label']) ?>
        </option>
    <?php endforeach ?>
</select>
<button type="button" class="btn btn-blue" id="ai-test-btn"
        data-test-url="<?= $this->url->href('SettingsController', 'testConnection', ['plugin' => 'AiConnector', 'csrf_token' => $ai_test_csrf]) ?>"
        data-msg-ok="<?= $this->text->e(t('Connection successful.')) ?>"
        data-msg-fail="<?= $this->text->e(t('Connection failed:')) ?>"
        data-msg-unknown="<?= $this->text->e(t('Unknown error')) ?>"
        data-msg-request-failed="<?= $this->text->e(t('Request failed:')) ?>">
    <?= t('Test Connection') ?>
</button>
<?php endif ?>
