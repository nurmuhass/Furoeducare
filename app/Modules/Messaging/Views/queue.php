<?php $root = $data['root']; $staff = $data['staff']; ?>
<section class="page-band"><div class="site-shell">
    <a class="text-link" href="/dashboard">Back to dashboard</a>
    <h1 class="my-3"><?= $escape($data['title']) ?></h1>
    <p>Private conversations with Furoeducare management. <?= (int) $data['unread'] ?> unread.</p>
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
</div></section>
<div class="site-shell section-space">
    <details class="form-panel mb-4"<?= $data['new'] ? ' open' : '' ?>>
        <summary class="h4">Start a conversation</summary>
        <p>Send text only. Do not share identity documents, passwords or sensitive identity numbers in chat.</p>
        <form class="auth-form" method="post" action="<?= $escape($root) ?>">
            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="token" value="<?= $escape($data['token']) ?>">
            <?php if ($staff): ?>
                <div><label class="form-label" for="message-owner">Owner account number</label><input class="form-control" id="message-owner" type="number" name="owner_id" min="1" value="<?= $escape((string) ($data['newOwner'] ?? '')) ?>" required></div>
            <?php endif; ?>
            <div><label class="form-label" for="message-role">Account role</label>
                <select class="form-select" id="message-role" name="owner_role" required>
                    <?php foreach ($staff ? \Furoeducare\Modules\Auth\Role::publicRoles() : $data['roles'] as $role): ?>
                        <option value="<?= $escape($role->value) ?>"<?= $staff && ($data['newRole'] ?? '') === $role->value ? ' selected' : '' ?>><?= $escape($role->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="form-label" for="message-subject">Subject</label><input class="form-control" id="message-subject" name="subject" maxlength="160" required></div>
            <div><label class="form-label" for="message-topic">Topic</label><select class="form-select" id="message-topic" name="context_type">
                <?php foreach ($data['contexts'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $data['context_type'] === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
            </select></div>
            <div><label class="form-label" for="message-context">Linked record number (optional)</label><input class="form-control" id="message-context" type="number" min="1" name="context_id" value="<?= $escape((string) ($data['context_id'] ?? '')) ?>"><p class="form-text">Use a record you can access. Linked records are checked before the conversation is created.</p></div>
            <div><label class="form-label" for="message-body">Message</label><textarea class="form-control" id="message-body" name="body" rows="5" maxlength="4000" required></textarea></div>
            <button class="btn button button-primary" type="submit">Start conversation</button>
        </form>
    </details>
    <?php if ($staff): ?>
        <form class="form-panel row g-3 mb-4" method="get" action="<?= $escape($root) ?>" aria-label="Filter conversations">
            <div class="col-md-4"><label class="form-label" for="message-search">Search subject or message</label><input class="form-control" id="message-search" name="q" maxlength="160" value="<?= $escape($data['filters']['q']) ?>"></div>
            <?php foreach (['status' => ['all' => 'All statuses', 'open' => 'Open', 'pending' => 'Pending', 'closed' => 'Closed'], 'assigned' => ['all' => 'All assignments', 'me' => 'Assigned to me', 'unassigned' => 'Unassigned'], 'context_type' => ['' => 'All topics'] + $data['contexts']] as $field => $options): ?>
                <div class="col-md-2"><label class="form-label" for="filter-<?= $escape($field) ?>"><?= $escape(ucfirst(str_replace('_', ' ', $field))) ?></label><select class="form-select" id="filter-<?= $escape($field) ?>" name="<?= $escape($field) ?>">
                    <?php foreach ($options as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $data['filters'][$field] === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
                </select></div>
            <?php endforeach; ?>
            <div class="col-md-2 d-flex align-items-end"><button class="btn button button-primary" type="submit">Filter</button></div>
        </form>
    <?php endif; ?>
    <section aria-labelledby="conversation-list"><h2 class="h4" id="conversation-list">Conversations</h2>
        <?php if ($data['threads'] === []): ?><p>No conversations to show.</p><?php else: ?>
            <ul class="list-unstyled">
                <?php foreach ($data['threads'] as $thread): ?><li class="border-bottom py-3">
                    <a class="fw-semibold text-break" href="<?= $escape($root . '?id=' . (int) $thread['id']) ?>"><?= $escape($thread['subject']) ?></a>
                    <span class="status-badge ms-2"><?= $escape(ucfirst($thread['status'])) ?></span>
                    <?php if ((int) $thread['unread'] > 0): ?><strong class="ms-2"><?= (int) $thread['unread'] ?> unread</strong><?php endif; ?>
                    <p class="mb-0 mt-1"><?= $escape(ucfirst($thread['owner_role'])) ?> · Updated <?= $escape($thread['updated_at']) ?> UTC<?php if ($staff): ?> · <?= $thread['assigned_admin_id'] === null ? 'Unassigned' : ((int) $thread['assigned_admin_id'] === $data['viewer'] ? 'Assigned to you' : 'Assigned to management') ?><?php endif; ?></p>
                </li><?php endforeach; ?>
            </ul>
            <?php if ($data['next'] !== null): ?><a class="text-link" href="<?= $escape($root . '?' . http_build_query($data['filters'] + ['before' => $data['next']])) ?>">Older conversations</a><?php endif; ?>
        <?php endif; ?>
    </section>
    <p class="form-text mt-4">Closed conversations are retained for 365 days unless a staff hold applies.</p>
</div>
<script src="/assets/messaging.js" defer></script>


