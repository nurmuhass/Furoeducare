<?php
use Furoeducare\Modules\Vacancy\VacancyInput;
use Furoeducare\Support\NigerianStates;

$facts = [
    'Subjects' => $fields['subjects'], 'Teaching level' => $fields['teaching_level'],
    'Required qualification' => $fields['qualification'], 'Minimum experience' => $fields['experience'] === '' ? '' : $fields['experience'] . ' years',
    'Employment type' => VacancyInput::EMPLOYMENT[$fields['employment_type']] ?? '',
    'Location' => implode(', ', array_filter([$fields['city'], NigerianStates::NAMES[$fields['state_code']] ?? ''])),
    'Application deadline' => $fields['deadline'] === '' ? '' : $fields['deadline'] . ' (Nigeria time)',
    'Number of positions' => $fields['positions'],
];
if ($fields['salary_min'] !== '' && $fields['salary_max'] !== '') {
    $facts['Salary range'] = '₦' . number_format((int) $fields['salary_min']) . ' to ₦' . number_format((int) $fields['salary_max']);
}
?>
<dl class="row">
    <?php foreach ($facts as $label => $value): ?>
        <dt class="col-sm-5"><?= $escape($label) ?></dt><dd class="col-sm-7 text-break"><?= $escape($value === '' ? 'Awaiting details' : $value) ?></dd>
    <?php endforeach; ?>
</dl>
<?php foreach (VacancyInput::LONG as $key): ?>
    <?php if ($fields[$key] !== ''): ?>
        <h3 class="h5 mt-4"><?= $escape(VacancyInput::TEXT[$key][0]) ?></h3>
        <p class="text-break"><?= nl2br($escape($fields[$key])) ?></p>
    <?php endif; ?>
<?php endforeach; ?>
