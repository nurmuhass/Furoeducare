<?php

$field = static function (
    string $name,
    string $label,
    mixed $value = '',
    string $type = 'text',
    array $options = [],
    string $error = '',
    string $hint = '',
    bool $required = false,
) use ($escape): void {
    $id = 'teacher-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', rtrim($name, ']'));
    $ids = array_filter([$error !== '' ? $id . '-error' : '', $hint !== '' ? $id . '-hint' : '']);
    $attributes = '';
    foreach ($type === 'select' ? [] : $options as $attribute => $setting) {
        $attributes .= ' ' . $attribute . '="' . $escape((string) $setting) . '"';
    }
    $control = 'id="' . $id . '" name="' . $escape($name) . '"'
        . ($error !== '' ? ' aria-invalid="true"' : '')
        . ($ids !== [] ? ' aria-describedby="' . implode(' ', $ids) . '"' : '');
    ?>
    <div class="form-field">
        <label class="form-label" for="<?= $id ?>"><?= $escape($label) ?></label>
        <?php if ($type === 'textarea'): ?>
            <textarea class="form-control" <?= $control ?><?= $attributes ?>><?= $escape((string) $value) ?></textarea>
        <?php elseif ($type === 'select'): ?>
            <select class="form-select" <?= $control ?> <?= $required ? 'required' : '' ?>>
                <option value="">Choose an option</option>
                <?php foreach ($options as $key => $option): ?>
                    <option value="<?= $escape($key) ?>" <?= $value === $key ? 'selected' : '' ?>><?= $escape($option) ?></option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input class="form-control" type="<?= $escape($type) ?>" <?= $control ?><?= $attributes ?>
                <?= in_array($type, ['password', 'file'], true) ? '' : 'value="' . $escape((string) $value) . '"' ?>>
        <?php endif; ?>
        <?php if ($hint !== ''): ?><p class="form-next" id="<?= $id ?>-hint"><?= $escape($hint) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="field-error" id="<?= $id ?>-error"><?= $escape($error) ?></p><?php endif; ?>
    </div>
    <?php
};

$contactFields = static function (array $values, array $errors) use ($field): void {
    $field('first_name', 'First name', $values['first_name'] ?? '', options: ['maxlength' => 80, 'autocomplete' => 'given-name', 'required' => ''], error: $errors['first_name'] ?? '');
    $field('last_name', 'Last name', $values['last_name'] ?? '', options: ['maxlength' => 80, 'autocomplete' => 'family-name', 'required' => ''], error: $errors['last_name'] ?? '');
    $field('phone', 'Phone number', $values['phone'] ?? '', 'tel', ['maxlength' => 48, 'autocomplete' => 'tel', 'required' => ''],
        $errors['phone'] ?? '', 'Use an 11-digit Nigerian number or include the international calling code.');
};
