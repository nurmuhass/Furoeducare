<?php

$state = \Furoeducare\Support\NigerianStates::NAMES[$listing['state_code']] ?? '';
$modes = \Furoeducare\Modules\Directory\DirectoryFilters::labels((int) $listing['current_modes']);
$years = (int) $listing['experience'];
$facts = [
    'Subjects' => implode(', ', $listing['subjects']),
    'Teaching levels' => implode(', ', $listing['levels']),
    'Highest relevant qualification' => $listing['qualification'],
    'Teaching experience' => $years . ($years === 1 ? ' year' : ' years'),
    'Location' => implode(', ', array_filter([$listing['city'], $state])),
    'Availability' => implode(', ', $modes),
];
?>
<dl class="directory-facts">
    <?php foreach ($facts as $label => $value): ?>
        <dt><?= $escape($label) ?></dt>
        <dd class="text-break"><?= $escape($value) ?></dd>
    <?php endforeach; ?>
</dl>
