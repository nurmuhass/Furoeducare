<?php

use Furoeducare\Modules\Teacher\DashboardPage;

$tab = $data['tab'];
$profile = $data['profile'];
$category = $profile['category'] === 'nysc' ? 'NYSC Member' : 'Teacher';
$complete = count(array_filter($data['missing'], static fn (array $items): bool => $items === []));
$total = count($data['missing']);
$percent = (int) round(100 * $complete / $total);
?>
<div class="teacher-main-inner dashboard-teacher-panel">
    <div class="teacher-page-heading">
        <h1><?= $escape($data['title']) ?></h1>
        <?php if ($tab === DashboardPage::Overview): ?><p>Welcome, <?= $escape($profile['first_name']) ?>.</p><?php endif; ?>
    </div>
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice teacher-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
    <?php require __DIR__ . '/' . $data['panel'] . '.php'; ?>
</div>
