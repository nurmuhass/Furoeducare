<?php
$appearance = $data['accountAppearance'] ?? null;
$appearance = in_array($appearance, ['light', 'dark', 'system'], true) ? $appearance : 'system';
?>
<?php if ($data['signedIn'] ?? false): ?>
<form method="post" action="/settings/account" data-theme-picker>
    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
    <input type="hidden" name="action" value="appearance">
    <input type="hidden" name="return_to" value="<?= $escape($data['appearanceReturn'] ?? '/settings/account') ?>">
<?php else: ?>
<div data-theme-picker hidden>
<?php endif; ?>
    <label class="form-label mb-1">Appearance
        <select name="appearance" class="form-select form-select-sm theme-select">
            <?php foreach (['system' => 'System default', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label): ?>
                <option value="<?= $value ?>"<?= $appearance === $value ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if ($data['signedIn'] ?? false): ?><noscript><button class="btn button button-outline button-small" type="submit">Save appearance</button></noscript><?php endif; ?>
<?php if ($data['signedIn'] ?? false): ?></form><?php else: ?></div><?php endif; ?>
