<div class="page-header">
    <h2><?= t('AI') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('AiConnectorProjectController', 'saveProject', ['project_id' => $project['id'], 'plugin' => 'AiConnector']) ?>">
    <?= $this->form->csrf() ?>

    <?= $this->form->label(t('AI profile for this project'), 'ai_profile') ?>
    <select name="ai_profile" id="ai_profile" class="form-select">
        <option value=""><?= t('Use the suite\'s settings') ?></option>
        <?php foreach ($profiles as $p): ?>
            <option value="<?= $this->text->e($p['id']) ?>" <?= $p['id'] === $values['ai_profile'] ? 'selected' : '' ?>><?= $this->text->e($p['label']) ?></option>
        <?php endforeach ?>
    </select>
    <p class="form-help"><?= t('Every AI feature in this project uses this profile, unless an admin locked the feature.') ?></p>

    <label>
        <input type="checkbox" name="members_switch" value="1" <?= $values['members_switch'] ? 'checked' : '' ?>>
        <?= t('Let members switch the AI model') ?>
    </label>
    <p class="form-help"><?= t('When on, people choose a model where a plugin offers the AI model picker.') ?></p>

    <?php if (! empty($locked)): ?>
        <h3><?= t('Locked by an admin') ?></h3>
        <p class="form-help"><?= t('These features always use the profile an admin assigned and ignore the choices above.') ?></p>
        <ul>
            <?php foreach ($locked as $f): ?>
                <li><?= $this->text->e($f['label']) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>
