<?php
$thread = $data['thread'];
$root = $data['root'];
$staff = $data['staff'];
$id = (int) $thread['id'];
$assigned = $staff && (int) $thread['assigned_admin_id'] === $data['viewer'];
$contextId = (int) ($thread['context_id'] ?? 0);
// Fixed destination routes enforce their own current record permissions.
$contextLink = null;
if ($contextId > 0) {
    if ($thread['context_type'] === 'training') {
        $contextLink = ($staff ? '/dashboard/admin/training/requests' : '/dashboard/school/training') . '?id=' . $contextId;
    } elseif ($thread['context_type'] === 'placement') {
        $contextLink = ($staff ? '/dashboard/admin/placements' : '/placements') . '?id=' . $contextId;
    } elseif ($thread['context_type'] === 'recruitment') {
        $contextLink = ($staff ? '/dashboard/admin/candidate-requests' : '/candidate-requests') . '?id=' . $contextId;
    } elseif ($thread['context_type'] === 'candidate_information') {
        $caseId = (int) ($thread['context_case_id'] ?? 0);
        if ($caseId > 0) {
            $contextLink = ($staff ? '/dashboard/admin/candidate-requests' : '/candidate-requests') . '?id=' . $caseId;
            if ($staff) {
                $contextLink .= '&disclosure=' . $contextId;
            }
        }
    } elseif ($thread['context_type'] === 'tutoring') {
        if ($staff) {
            $contextLink = '/dashboard/admin/tutoring-requests?id=' . $contextId;
        } elseif ($thread['owner_role'] === 'parent') {
            $contextLink = '/dashboard/parent?tab=requests&id=' . $contextId;
        }
    } elseif ($thread['context_type'] === 'vacancy') {
        if ($staff) {
            $contextLink = '/dashboard/admin/vacancies?id=' . $contextId;
        } elseif ($thread['owner_role'] === 'school') {
            $contextLink = '/dashboard/school/vacancies?id=' . $contextId;
        } else {
            $contextLink = '/teaching-jobs/view?id=' . $contextId;
        }
    }
}
?>
<section class="page-band"><div class="site-shell">
    <a class="text-link" href="<?= $escape($root) ?>">Back to conversations</a>
    <h1 class="my-3 text-break"><?= $escape($thread['subject']) ?></h1>
    <p>Conversation #<?= $id ?> <span class="status-badge ms-2"><?= $escape(ucfirst($thread['status'])) ?></span></p>
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
</div></section>
<div class="site-shell section-space">
    <section class="form-panel mb-4" aria-labelledby="conversation-details"><h2 class="h4" id="conversation-details">Conversation details</h2>
        <p>Topic: <?= $escape($data['contexts'][$thread['context_type']] ?? 'General support') ?><?php if ($contextId > 0): ?> · <?php if ($contextLink !== null): ?><a href="<?= $escape($contextLink) ?>">View linked record #<?= $contextId ?></a><?php else: ?>Linked record #<?= $contextId ?><?php endif; ?><?php endif; ?></p>
        <?php if ($staff): ?>
            <p>Owner account #<?= (int) $thread['owner_id'] ?> · <?= $escape(ucfirst($thread['owner_role'])) ?> · <?= $assigned ? 'Assigned to you' : ($thread['assigned_admin_id'] === null ? 'Unassigned' : 'Assigned to another administrator') ?></p>
            <p>Retention hold: <?= (int) $thread['retention_hold'] === 1 ? 'Active' : 'None' ?></p>
            <div class="d-flex flex-wrap gap-3">
                <?php if (!$assigned): ?><form method="post" action="<?= $escape($root) ?>">
                    <?php require __DIR__ . '/management-fields.php'; ?>
                    <button class="btn button button-primary" type="submit" name="action" value="assign">Assign to me</button>
                </form><?php endif; ?>
                <form method="post" action="<?= $escape($root) ?>" class="d-flex flex-wrap gap-2 align-items-center">
                    <?php require __DIR__ . '/management-fields.php'; ?>
                    <label class="form-label mb-0" for="conversation-status">Status</label>
                    <select class="form-select w-auto" id="conversation-status" name="status"><?php foreach (['open', 'pending', 'closed'] as $status): ?><option value="<?= $escape($status) ?>"<?= $thread['status'] === $status ? ' selected' : '' ?>><?= $escape(ucfirst($status)) ?></option><?php endforeach; ?></select>
                    <button class="btn button button-primary" type="submit" name="action" value="status">Update status</button>
                </form>
                <form method="post" action="<?= $escape($root) ?>">
                    <?php require __DIR__ . '/management-fields.php'; ?>
                    <input type="hidden" name="hold" value="<?= (int) $thread['retention_hold'] === 1 ? '0' : '1' ?>">
                    <button class="btn button button-primary" type="submit" name="action" value="hold"><?= (int) $thread['retention_hold'] === 1 ? 'Release retention hold' : 'Apply retention hold' ?></button>
                </form>
            </div>
        <?php endif; ?>
    </section>
    <section aria-labelledby="conversation-messages"><h2 class="h4" id="conversation-messages">Messages</h2>
        <p>
            <?php if ($data['next'] !== null): ?><a class="text-link" href="<?= $escape($root . '?id=' . $id . '&before=' . $data['next']) ?>">Older messages</a> · <?php endif; ?>
            <a href="<?= $escape($root . '?id=' . $id) ?>">Refresh latest messages</a>
        </p>
        <?php if ($data['messages'] === []): ?><p>No messages to show.</p><?php else: ?>
            <ol class="list-unstyled">
                <?php foreach ($data['messages'] as $message): ?><li class="form-panel mb-3" data-message-id="<?= (int) $message['id'] ?>">
                    <p class="mb-2"><strong><?= (int) $message['is_staff'] === 1 ? 'Furoeducare management' : ((int) $message['sender_id'] === $data['viewer'] ? 'You' : ucfirst($thread['owner_role'])) ?></strong> <span class="ms-2 text-muted"><?= $escape($message['created_at']) ?> UTC</span></p>
                    <p class="small text-muted"><?= !empty($message['is_read']) ? 'Read' : 'Unread' ?></p>
                    <p class="text-break mb-0" style="white-space: pre-wrap"><?= $escape($message['body']) ?></p>
                </li><?php endforeach; ?>
            </ol>
            <form method="post" action="<?= $escape($root) ?>" data-message-read data-read-endpoint="<?= $escape($root . '/read') ?>">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <input type="hidden" name="action" value="read">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="last_id" value="<?= (int) $data['last'] ?>">
                <button class="btn button" type="submit">Mark displayed messages as read</button>
                <span class="ms-2" role="status" data-read-feedback></span>
            </form>
        <?php endif; ?>
    </section>
    <section class="form-panel mt-4" aria-labelledby="reply-heading"><h2 class="h4" id="reply-heading">Reply</h2>
        <?php if ($thread['status'] === 'closed'): ?><p>This conversation is closed. Management must reopen it before further replies.</p>
        <?php elseif ($staff && !$assigned): ?><p>Assign this conversation to yourself before replying.</p>
        <?php else: ?>
            <p>Send text only. Do not share identity documents, passwords or sensitive identity numbers in chat.</p>
            <form class="auth-form" method="post" action="<?= $escape($root) ?>">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="token" value="<?= $escape($data['token']) ?>">
                <div><label class="form-label" for="reply-body">Message</label><textarea class="form-control" id="reply-body" name="body" rows="5" maxlength="4000" required></textarea></div>
                <button class="btn button button-primary" type="submit">Send message</button>
            </form>
        <?php endif; ?>
    </section>
    <p class="form-text mt-4">Closed conversations are retained for 365 days unless a staff hold applies.</p>
</div>
<script src="/assets/messaging.js" defer></script>



