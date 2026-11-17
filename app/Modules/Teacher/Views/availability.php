<?php

use Furoeducare\Modules\Teacher\TeacherInput;

$options = $values['opportunities'] ?? (($values['status'] ?? '') === 'unavailable' ? ['unavailable'] : []);
?>
<fieldset class="teacher-availability-options" <?= isset($errors['opportunities']) ? 'aria-describedby="availability-help availability-error"' : 'aria-describedby="availability-help"' ?>>
    <legend class="h5">Current availability</legend>
    <p id="availability-help" class="teacher-muted">Choose all that apply. Choose temporarily unavailable on its own.</p>
    <?php if ($options === [] && ($values['status'] ?? '') === 'now'): ?>
        <p class="teacher-muted">Available now is already saved. Choose the types of work you can take on.</p>
    <?php endif; ?>
    <input type="hidden" name="availability_preferences" value="1">
    <?php foreach (TeacherInput::OPPORTUNITIES as $key => $text): ?>
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="availability-<?= $key ?>" name="opportunities[]" value="<?= $key ?>"
                <?= in_array($key, $options, true) ? 'checked' : '' ?> <?= isset($errors['opportunities']) ? 'aria-invalid="true"' : '' ?>>
            <label class="form-check-label" for="availability-<?= $key ?>"><?= $escape($text) ?></label>
        </div>
    <?php endforeach; ?>
    <?php if (isset($errors['opportunities'])): ?><p class="field-error" id="availability-error"><?= $escape($errors['opportunities']) ?></p><?php endif; ?>
</fieldset>
<?php
$field('date', 'Available from (optional)', $values['date'] ?? '', 'date', error: $errors['date'] ?? '',
    hint: 'Add a start date if you are available from a future date.');
