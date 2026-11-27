<?php if ($this->user->hasProjectAccess('AiConnectorProjectController', 'show', $project['id'])): ?>
<li <?= $this->app->checkMenuSelection('AiConnectorProjectController', 'show', 'AiConnector') ?>>
    <?= $this->url->link(t('AI'), 'AiConnectorProjectController', 'show', ['project_id' => $project['id'], 'plugin' => 'AiConnector']) ?>
</li>
<?php endif ?>
