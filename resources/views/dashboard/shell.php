<?php
$navigation = $data['dashboardNavigation'];
$active = \Furoeducare\UI\DashboardNavigation::active($navigation, $data['dashboardPath'], $data['dashboardQuery']);
$role = $data['dashboardRole'];
$readonly = ($data['adminWorkspace'] ?? false) && ($data['adminCanManage'] ?? true) === false;
?>
<div class="dashboard-workspace<?= ($data['adminWorkspace'] ?? false) ? ' admin-workspace' : '' ?>" data-dashboard-workspace>
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="dashboard-body">
        <?php require __DIR__ . '/header.php'; ?>
        <?php if (($data['adminNotice'] ?? '') !== ''): ?><p class="dashboard-notice" role="status"><?= $escape($data['adminNotice']) ?></p><?php endif; ?>
        <main id="main" tabindex="-1" class="dashboard-main<?= ($data['adminWorkspace'] ?? false) ? ' admin-main' : '' ?>"<?= $readonly ? ' data-admin-readonly' : '' ?>>
            <?php if ($readonly): ?><aside class="dashboard-notice"><strong>Read-only access</strong><p>You can review this module. Changes require management permission.</p></aside><?php endif; ?>
            <?= $data['content'] ?>
        </main>
        <footer class="dashboard-footer"><span><?= $escape($data['appName']) ?> · <?= $escape($role->label()) ?></span><?php if (($data['adminSupportEmail'] ?? '') !== ''): ?><a href="mailto:<?= $escape($data['adminSupportEmail']) ?>">Contact support</a><?php else: ?><a href="/contact">Contact Furoeducare</a><?php endif; ?></footer>
    </div>
    <p class="dashboard-loading" role="status" aria-live="polite" data-dashboard-loading hidden>Processing your request…</p>
    <?php require __DIR__ . '/confirmation.php'; ?>
</div>
