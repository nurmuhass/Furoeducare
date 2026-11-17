<?php
use Furoeducare\Modules\Parent\TutoringInput;
use Furoeducare\Support\NigerianStates;

$lesson = $record['details'];
$state = NigerianStates::NAMES[$lesson['state_code'] ?? ''] ?? '';
$location = implode(', ', array_filter([$lesson['city'] ?? '', $state]));
$daysText = implode(', ', array_map(static fn (string $day): string => TutoringInput::DAYS[$day], $lesson['days'] ?? []));
$timesText = implode(', ', array_map(static fn (array $time): string => $time['from'] . '–' . $time['to'], $lesson['times'] ?? []));
$requestFacts = [
    'Requested teacher' => $record['selected_teacher_reference'] ?? '',
    'Confirmed tutor' => $record['confirmed_reference'] ?? '',
    'Student education level' => TutoringInput::LEVELS[$lesson['level'] ?? ''] ?? '',
    'Number of learners' => $lesson['learners'] ?? '',
    'Lesson format' => TutoringInput::MODES[$lesson['mode'] ?? ''] ?? '',
    'General location' => $location,
    'Preferred days' => $daysText,
    'Preferred times (WAT)' => $timesText,
    'Sessions per week' => $lesson['sessions'] ?? '',
];
?>
<dl class="row mb-0">
    <?php foreach ($requestFacts as $label => $value): ?>
        <?php if ($value === '') continue; ?>
        <dt class="col-sm-5"><?= $escape($label) ?></dt><dd class="col-sm-7 text-break"><?= $escape((string) $value) ?></dd>
    <?php endforeach; ?>
</dl>
<?php foreach (['Learning objectives' => $lesson['objectives'] ?? '', 'Required qualifications or experience' => $lesson['qualifications'] ?? '',
    'Additional requirements' => $record['requirements']] as $label => $description): ?>
    <?php if ($description === '') continue; ?>
    <h3 class="h6 mt-3"><?= $escape($label) ?></h3>
    <p class="text-break"><?= nl2br($escape($description)) ?></p>
<?php endforeach; ?>
