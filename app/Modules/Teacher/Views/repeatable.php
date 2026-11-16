<?php

use Furoeducare\Modules\Teacher\TeacherInput;
use Furoeducare\Modules\Teacher\Section;

$education = $section === Section::Qualifications;
$entry = $education ? 'Qualification' : 'Experience';
$rows = $values['rows'] ?? [];
$fields = $education
    ? ['qualification' => ['Qualification', 160], 'institution' => ['Institution', 160], 'field_of_study' => ['Field of study', 160], 'completed_year' => ['Completion year', 4]]
    : ['position_title' => ['Role or position', 160], 'organisation' => ['Organisation', 160], 'started_year' => ['Start year', 4],
        'ended_year' => ['End year', 4], 'description' => ['Description', 1000]];
$renderRow = static function (int|string $index, string $legend, array $row) use ($field, $fields, $errors, $escape): void {
    ?>
    <fieldset class="border rounded p-3 d-grid gap-3">
        <legend class="float-none w-auto px-2 h6"><?= $escape($legend) ?></legend>
        <?php foreach ($fields as $key => [$label, $length]): ?>
            <?php
            $options = ['maxlength' => $length];
            if ($length === 4) {
                $options['inputmode'] = 'numeric';
            }
            if ($key === 'description') {
                $options['rows'] = 3;
            }
            $field("rows[$index][$key]", $label, $row[$key] ?? '', $key === 'description' ? 'textarea' : 'text',
                $options, $errors["rows.$index.$key"] ?? '', $key === 'ended_year' ? 'Leave blank for a current role.' : '');
            ?>
        <?php endforeach; ?>
        <?php if ($row !== []): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="rows[<?= $index ?>][remove]" value="1" id="remove-row-<?= $index ?>">
                <label class="form-check-label" for="remove-row-<?= $index ?>">Remove this entry on save</label>
            </div>
        <?php endif; ?>
    </fieldset>
    <?php
};
?>
<p class="form-next">Save entries as you go. Leave extra entries blank.</p>
<?php if (isset($errors['rows'])): ?><p class="field-error"><?= $escape($errors['rows']) ?></p><?php endif; ?>
<?php if (!$education): ?>
    <div class="form-check">
        <input class="form-check-input" id="teacher-no-experience" type="checkbox" name="no_experience" value="1"
            <?= !empty($values['no_experience']) ? 'checked' : '' ?>
            <?= isset($errors['no_experience']) ? 'aria-invalid="true" aria-describedby="teacher-no-experience-error"' : '' ?>>
        <label class="form-check-label" for="teacher-no-experience">I have no work or teaching experience yet</label>
        <?php if (isset($errors['no_experience'])): ?><p id="teacher-no-experience-error" class="field-error"><?= $escape($errors['no_experience']) ?></p><?php endif; ?>
    </div>
<?php endif; ?>
<div class="d-grid gap-3" data-teacher-entries>
    <?php foreach ($rows as $index => $row): ?>
        <?php $renderRow($index, $entry . ' ' . ($index + 1), $row); ?>
    <?php endforeach; ?>
    <?php if (count($rows) < TeacherInput::ROW_LIMIT): ?>
        <?php $renderRow(count($rows), $entry . ' ' . (count($rows) + 1), []); ?>
    <?php endif; ?>
</div>
<?php if (count($rows) + 1 < TeacherInput::ROW_LIMIT): ?>
    <template data-teacher-entry-template><?php $renderRow('ROWINDEX', $entry . ' ROWNUMBER', []); ?></template>
    <button type="button" class="btn button button-outline align-self-start" data-add-teacher-entry
        data-entry-limit="<?= TeacherInput::ROW_LIMIT ?>" hidden>Add another <?= $escape(strtolower($entry)) ?></button>
<?php endif; ?>
