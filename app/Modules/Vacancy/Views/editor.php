<?php
use Furoeducare\Modules\Vacancy\VacancyInput;
use Furoeducare\Support\NigerianStates;

$values = $data['values'] ?? ($vacancy['fields'] ?? []);
$errors = $data['errors'];
$selects = [
    'employment_type' => ['Employment type', VacancyInput::EMPLOYMENT], 'state_code' => ['State or FCT', NigerianStates::NAMES],
];
$order = ['title', 'subjects', 'teaching_level', 'qualification', 'experience', 'employment_type', 'state_code', 'city',
    'positions', 'deadline', 'salary_min', 'salary_max', 'description', 'responsibilities', 'skills'];
?>
<form method="post" action="/dashboard/school/vacancies" class="row g-3">
    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
    <input type="hidden" name="action" value="save">
    <?php if ($vacancy !== null): ?>
        <input type="hidden" name="id" value="<?= (int) $vacancy['id'] ?>">
        <input type="hidden" name="revision" value="<?= (int) $vacancy['revision'] ?>">
    <?php endif; ?>
    <?php if ($errors !== []): ?><p class="form-summary" role="alert">Review the highlighted fields and try again.</p><?php endif; ?>
    <?php foreach ($order as $key): ?>
        <?php
        $label = (VacancyInput::TEXT[$key] ?? VacancyInput::NUMBERS[$key] ?? $selects[$key] ?? ['Application deadline'])[0];
        $long = in_array($key, VacancyInput::LONG, true);
        $value = $values[$key] ?? '';
        $inputClass = isset($selects[$key]) ? 'form-select' : 'form-control';
        $invalid = isset($errors[$key]);
        ?>
        <div class="<?= $long || $key === 'title' ? 'col-12' : 'col-md-6' ?>">
            <label class="form-label" for="vacancy-<?= $key ?>"><?= $escape($label) ?><?= str_starts_with($key, 'salary_') ? ' (optional)' : '' ?></label>
            <?php if (isset($selects[$key])): ?>
                <select class="<?= $inputClass ?><?= $invalid ? ' is-invalid' : '' ?>" id="vacancy-<?= $key ?>" name="<?= $key ?>"
                    <?php if ($invalid): ?>aria-invalid="true" aria-describedby="vacancy-error-<?= $key ?>"<?php endif; ?>>
                    <option value="">Choose <?= $escape(strtolower($label)) ?></option>
                    <?php foreach ($selects[$key][1] as $option => $text): ?>
                        <option value="<?= $escape($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= $escape($text) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($long): ?>
                <textarea class="<?= $inputClass ?><?= $invalid ? ' is-invalid' : '' ?>" id="vacancy-<?= $key ?>" name="<?= $key ?>" rows="4"
                    maxlength="<?= VacancyInput::TEXT[$key][1] ?>"
                    <?php if ($invalid): ?>aria-invalid="true" aria-describedby="vacancy-error-<?= $key ?>"<?php endif; ?>><?= $escape($value) ?></textarea>
            <?php else: ?>
                <input class="<?= $inputClass ?><?= $invalid ? ' is-invalid' : '' ?>" id="vacancy-<?= $key ?>" name="<?= $key ?>"
                    type="<?= $key === 'deadline' ? 'date' : (isset(VacancyInput::NUMBERS[$key]) ? 'number' : 'text') ?>"
                    <?php if (isset(VacancyInput::NUMBERS[$key])): ?>min="<?= VacancyInput::NUMBERS[$key][1] ?>" max="<?= VacancyInput::NUMBERS[$key][2] ?>" step="1"<?php endif; ?>
                    <?php if (isset(VacancyInput::TEXT[$key])): ?>maxlength="<?= VacancyInput::TEXT[$key][1] ?>"<?php endif; ?>
                    <?php if ($invalid): ?>aria-invalid="true" aria-describedby="vacancy-error-<?= $key ?>"<?php endif; ?>
                    value="<?= $escape($value) ?>">
            <?php endif; ?>
            <?php if ($invalid): ?><p class="invalid-feedback mb-0" id="vacancy-error-<?= $key ?>"><?= $escape($errors[$key]) ?></p><?php endif; ?>
            <?php if ($key === 'subjects'): ?><small class="form-text">Use commas to separate subjects.</small><?php endif; ?>
            <?php if ($key === 'deadline'): ?><small class="form-text">Applications close at the end of this date in Nigeria.</small><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <div class="col-12"><button class="btn button button-primary" type="submit"><?= $vacancy === null ? 'Save draft' : 'Save changes' ?></button></div>
</form>
