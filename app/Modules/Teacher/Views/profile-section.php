<?php

use Furoeducare\Modules\Teacher\TeacherInput;
use Furoeducare\Modules\Teacher\Section;

$list = static function (string $name, string $label) use ($field, $values, $errors): void {
    $field($name, $label, implode("\n", $values[$name] ?? []), 'textarea', ['rows' => 3, 'maxlength' => 2500],
        $errors[$name] ?? '', 'Enter one item per line.');
};

switch ($section) {
    case Section::Personal:
        $field('email', 'Verified email address', $data['email'], 'email', ['readonly' => '', 'autocomplete' => 'email']);
        $field('account_category', 'Account category', $data['profile']['category'] === 'nysc' ? 'NYSC Member' : 'Teacher', options: ['readonly' => '']);
        $contactFields($values, $errors);
        if ($data['profile']['category'] === 'nysc') {
            ?>
            <fieldset class="d-grid gap-3">
                <legend class="h5">NYSC details (optional)</legend>
                <?php
                $nysc = $values['nysc'] ?? [];
                $field('nysc_batch', 'Service batch or stream', $nysc['batch'] ?? '', options: ['maxlength' => 40], error: $errors['nysc_batch'] ?? '');
                $field('nysc_state', 'State of service', $nysc['state'] ?? '', options: ['maxlength' => 80], error: $errors['nysc_state'] ?? '');
                $field('nysc_year', 'Service year', $nysc['year'] ?? '', options: ['inputmode' => 'numeric', 'maxlength' => 4], error: $errors['nysc_year'] ?? '');
                ?>
            </fieldset>
            <?php
        }
        break;
    case Section::Biography:
        $field('bio', 'Professional biography', $values['bio'] ?? '', 'textarea', ['maxlength' => 2500, 'rows' => 7],
            $errors['bio'] ?? '', 'Summarise your teaching approach and relevant background.');
        break;
    case Section::Qualifications:
    case Section::Experience:
        require __DIR__ . '/repeatable.php';
        break;
    case Section::Subjects:
        $list('subjects', 'Subjects');
        $list('levels', 'Teaching levels');
        break;
    case Section::Skills:
        $list('skills', 'Skills');
        $list('certifications', 'Certifications (optional)');
        break;
    case Section::Locations:
        $list('locations', 'Preferred towns, cities or states');
        break;
    case Section::Preferences:
        foreach (['employment' => ['Employment', TeacherInput::EMPLOYMENT], 'tutoring' => ['Tutoring', TeacherInput::TUTORING]] as $key => [$label, $choices]) {
            ?>
            <fieldset>
                <legend class="h5"><?= $escape($label) ?></legend>
                <?php foreach ($choices as $choice => $text): ?>
                    <div class="form-check">
                        <input id="teacher-<?= $key ?>-<?= $choice ?>" class="form-check-input" name="<?= $key ?>[]" type="checkbox" value="<?= $choice ?>"
                            <?= in_array($choice, $values[$key] ?? [], true) ? 'checked' : '' ?>
                            <?= isset($errors[$key]) ? 'aria-invalid="true" aria-describedby="teacher-' . $key . '-error"' : '' ?>>
                        <label for="teacher-<?= $key ?>-<?= $choice ?>" class="form-check-label"><?= $escape($text) ?></label>
                    </div>
                <?php endforeach; ?>
                <?php if (isset($errors[$key])): ?><p id="teacher-<?= $key ?>-error" class="field-error"><?= $escape($errors[$key]) ?></p><?php endif; ?>
            </fieldset>
            <?php
        }
        break;
    case Section::Availability:
    case Section::Documents:
        break;
}
