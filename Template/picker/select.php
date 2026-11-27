<?php
/**
 * The shared AI profile picker. Consumers render it as AiConnector:picker/select with:
 *   options  ProviderRegistry::pickerOptions($feature, $projectId), computed by the controller
 *   name     the field name (default "ai_profile"); post it back as resolve()'s $pick
 * It renders nothing when options['show'] is false.
 */
$name = $name ?? 'ai_profile';
?>
<?php if (! empty($options['show'])): ?>
<div class="form-group">
    <?= $this->form->label(t('AI model'), $name) ?>
    <select name="<?= $this->text->e($name) ?>" id="<?= $this->text->e($name) ?>" class="form-select">
        <?php foreach ($options['profiles'] as $p): ?>
            <option value="<?= $this->text->e($p['id']) ?>" <?= $p['id'] === $options['selected'] ? 'selected' : '' ?>><?= $this->text->e($p['label']) ?></option>
        <?php endforeach ?>
    </select>
</div>
<?php endif ?>
