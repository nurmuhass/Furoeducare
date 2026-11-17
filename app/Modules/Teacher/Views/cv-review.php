<?php

use Furoeducare\Modules\Teacher\Section;

require __DIR__ . '/fields.php';

$job = $data['job'];
$draft = $data['draft'];
$values = $data['values'];
$errors = $data['errors'];
$profile = $data['profile']['sections'];
$badge = static function (string $key) use ($draft): void {
    $missing = $draft[$key] === [] || $draft[$key] === null;
    ?>
    <span class="badge text-bg-light"><?= $missing ? 'Missing' : 'Check accuracy' ?></span>
    <?php
};
?>
<div class="form-panel teacher-editor teacher-cv-review">
    <a href="/dashboard/teacher?section=documents">Back to CV and professional documents</a>
    <p>Review the suggestions from <strong><?= $escape($job['display_name']) ?></strong>. Correct any errors, fill in details you know, and leave missing values blank if you’re unsure.</p>
    <p>Saving adds reviewed entries to your profile and fills an empty biography. It keeps your saved details, identity, contact information and teaching levels.</p>
    <?php if ($errors !== []): ?>
        <p class="form-summary" role="alert">Review the highlighted fields and try again.</p>
    <?php endif; ?>

    <form class="auth-form" method="post" action="/dashboard/teacher/cv">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <input type="hidden" name="document_id" value="<?= (int) $job['document_id'] ?>">

        <fieldset class="border rounded p-3">
            <legend class="float-none w-auto px-2 h5">Professional biography <?php $badge('summary'); ?></legend>
            <?php if (!empty($profile['biography']['bio'])): ?>
                <p>Your saved biography will be kept. <a href="/dashboard/teacher?section=biography">Edit the saved biography</a>.</p>
            <?php endif; ?>
            <?php $field('summary', 'Suggested biography', $values['summary'] ?? '', 'textarea',
                ['maxlength' => 2500, 'rows' => 5, 'placeholder' => 'No biography found'], $errors['summary'] ?? ''); ?>
        </fieldset>

        <?php foreach ([Section::Qualifications, Section::Experience] as $section): ?>
            <fieldset class="border rounded p-3">
                <legend class="float-none w-auto px-2 h5"><?= $escape($section->label()) ?> <?php $badge($section->value); ?></legend>
                <?php
                if ($section === Section::Experience && !empty($profile['experience']['no_experience'])) {
                    echo '<p>Your saved profile says you have no work experience. <a href="/dashboard/teacher?section=experience">Edit that section</a> to add work experience.</p>';
                }
                $rowKey = $section->value;
                require __DIR__ . '/repeatable.php';
                ?>
            </fieldset>
        <?php endforeach; ?>

        <?php foreach (['subjects' => 'Subjects', 'skills' => 'Skills', 'certifications' => 'Certifications'] as $name => $label): ?>
            <fieldset class="border rounded p-3">
                <legend class="float-none w-auto px-2 h5"><?= $escape($label) ?> <?php $badge($name); ?></legend>
                <?php $field($name, 'Suggested ' . strtolower($label), implode("\n", $values[$name]), 'textarea',
                    ['maxlength' => 2600, 'rows' => 3, 'placeholder' => 'No suggestions found'], $errors[$name] ?? '', 'Use one entry per line.'); ?>
            </fieldset>
        <?php endforeach; ?>

        <div class="form-check">
            <input class="form-check-input" id="cv-confirmed" name="confirmed" type="checkbox" value="1" required
                <?= isset($errors['confirmed']) ? 'aria-invalid="true" aria-describedby="cv-confirmed-error"' : '' ?>>
            <label class="form-check-label" for="cv-confirmed">I reviewed these suggestions and want to save the information shown.</label>
            <?php if (isset($errors['confirmed'])): ?><p class="field-error" id="cv-confirmed-error"><?= $escape($errors['confirmed']) ?></p><?php endif; ?>
        </div>
        <button class="btn button button-primary" type="submit">Save reviewed profile</button>
    </form>
</div>
