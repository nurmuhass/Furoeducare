<?php
$rows = $data['rows'];
$page = array_slice($rows, 0, 20);
$filters = array_filter(['category' => $data['category'], 'unread' => $data['unreadOnly'] ? '1' : '']);
?>
<section class="page-band">
    <div class="site-shell">
        <h1>Notifications</h1>
        <p class="page-lede"><?= (int) $data['unread'] ?> unread notification<?= $data['unread'] === 1 ? '' : 's' ?></p>
        <nav class="d-flex gap-3 flex-wrap" aria-label="Notification navigation">
            <a href="/dashboard">Dashboard</a>
            <a href="/settings/notifications">Notification settings</a>
        </nav>
    </div>
</section>
<div class="site-shell section-space">
    <?php if ($data['notice'] !== ''): ?>
        <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
    <?php endif; ?>
    <?= \Furoeducare\UI\Components::filters('/notifications', [
        'category' => ['label' => 'Category', 'options' => ['' => 'All categories'] + $data['categories']],
        'unread' => ['label' => 'Unread only', 'type' => 'checkbox'],
    ], ['category' => $data['category'], 'unread' => $data['unreadOnly'] ? '1' : '']) ?>
    <?php if ($page !== [] && $data['unread'] > 0): ?>
        <form method="post" action="/notifications/read" class="mb-4">
            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
            <input type="hidden" name="action" value="all">
            <input type="hidden" name="through" value="<?= (int) $page[0]['id'] ?>">
            <button class="btn button button-outline" type="submit"><?= $filters !== [] || $data['before'] !== PHP_INT_MAX ? 'Mark these and earlier notifications as read' : 'Mark all as read' ?></button>
            <p class="small mt-2">Marks notifications up to the newest item on this page, across all categories. New arrivals stay unread.</p>
        </form>
    <?php endif; ?>
    <?php if ($page === []): ?>
        <?= \Furoeducare\UI\Components::empty('No notifications to show', $filters === [] ? 'Updates about your account and activity will appear here.' : 'No notifications match these filters. Try clearing your filters.') ?>
    <?php else: ?>
        <ol class="list-unstyled d-grid gap-3" aria-label="Notifications">
            <?php foreach ($page as $item): ?>
                <li><article class="form-panel<?= $item['read_at'] === null ? ' border-primary' : '' ?>">
                    <div class="d-flex gap-2 flex-wrap align-items-center mb-2">
                        <span class="status-badge"><?= $escape($data['categories'][$item['category']] ?? 'Account updates') ?></span>
                        <?php if ($item['read_at'] === null): ?><strong class="small">Unread</strong><?php endif; ?>
                    </div>
                    <h2 class="h4"><a href="/notifications/open?id=<?= (int) $item['id'] ?>"><?= $escape($item['title']) ?></a></h2>
                    <p class="text-break"><?= nl2br($escape($item['message'])) ?></p>
                    <p class="small">Received <?= $escape($data['wat']($item['created_at'])) ?> WAT</p>
                    <?php if ($item['read_at'] === null): ?>
                        <form method="post" action="/notifications/read">
                            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                            <input type="hidden" name="action" value="read">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <button class="btn button button-outline button-small" type="submit" aria-label="<?= $escape('Mark as read: ' . $item['title']) ?>">Mark as read</button>
                        </form>
                    <?php endif; ?>
                </article></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <nav class="d-flex gap-3 flex-wrap mt-4" aria-label="Notification pages">
        <?php if ($data['before'] !== PHP_INT_MAX): ?><a href="<?= $escape('/notifications' . ($filters === [] ? '' : '?' . http_build_query($filters))) ?>">Newest notifications</a><?php endif; ?>
        <?php if (count($rows) > 20): ?><a class="btn button button-outline" href="<?= $escape('/notifications?' . http_build_query($filters + ['before' => (int) $page[19]['id']])) ?>">Older notifications</a><?php endif; ?>
    </nav>
</div>
