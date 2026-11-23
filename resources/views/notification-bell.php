<?php $notificationUnread = max(0, (int) ($data['notificationUnread'] ?? 0)); ?>
<a class="nav-login d-inline-flex align-items-center gap-1" href="/notifications"<?= ($data['path'] ?? '') === '/notifications' ? ' aria-current="page"' : '' ?> aria-label="<?= $escape('Notifications, ' . $notificationUnread . ' unread') ?>">
    <svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
    <span class="d-none d-sm-inline">Notifications</span>
    <span class="badge rounded-pill bg-primary" aria-hidden="true"><?= $notificationUnread > 99 ? '99+' : $notificationUnread ?></span>
</a>
