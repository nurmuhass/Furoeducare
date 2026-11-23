<?php
$resource = $data['resource'];
$editing = $resource !== [];
$submitted = $data['values'] ?? [];
$values = $data['values'] ?? $resource;
$value = static function (string $key) use ($values, $resource, $submitted): string {
    if (in_array($key, ['categories', 'subjects', 'levels'], true)) {
        return isset($values[$key]) ? (string) $values[$key] : implode("\n", $resource['tags'][$key] ?? []);
    }
    if ($key === 'rights_confirmed') {
        return (string) ($submitted['rights_confirmed'] ?? '');
    }
    return (string) ($values[$key] ?? '');
};
?>
<section class="page-band"><div class="site-shell"><a href="/dashboard/admin/library">Back to library management</a><h1 class="mt-3"><?= $escape($data['title']) ?></h1>
<?php if ($editing): ?><p><span class="status-badge"><?= $escape(ucfirst($resource['status'])) ?></span> · <?= $escape($resource['reference']) ?> · Revision <?= (int) $resource['revision'] ?></p><?php endif; ?>
</div></section>
<div class="site-shell section-space">
    <?php if ($editing && $resource['status'] === 'removed'): ?>
        <section class="form-panel"><h2 class="h4">Resource removed</h2>
            <p>The files and catalogue metadata have been removed. Download history remains linked to this reference.</p>
            <p><?= (int) $resource['downloads'] ?> recorded downloads.</p>
            <a href="/dashboard/admin/library">Return to library management</a>
        </section>
    </div>
    <?php return; endif; ?>
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
    <?php if ($data['error'] !== ''): ?><div class="alert alert-danger" role="alert"><?= $escape($data['error']) ?> Please reselect any files before submitting.</div><?php endif; ?>
    <div class="row g-4"><div class="<?= $editing ? 'col-lg-8' : 'col-12' ?>">
    <form class="form-panel auth-form" method="post" enctype="multipart/form-data" action="/dashboard/admin/library">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>"><input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $resource['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $resource['revision'] ?>"><?php endif; ?>
        <h2 class="h4">Resource details</h2>
        <div class="row g-3">
            <?php foreach (['title' => ['Title', 200, true], 'author' => ['Author', 160, false], 'publisher' => ['Publisher', 160, false], 'edition' => ['Edition', 80, false], 'isbn' => ['ISBN', 32, false]] as $key => [$label, $limit, $required]): ?>
                <div class="<?= $key === 'title' ? 'col-12' : 'col-md-6' ?>"><label class="form-label" for="lib-<?= $escape($key) ?>"><?= $escape($label) ?><?= $required ? ' (required)' : '' ?></label><input class="form-control" id="lib-<?= $escape($key) ?>" name="<?= $escape($key) ?>" maxlength="<?= $limit ?>" value="<?= $escape($value($key)) ?>"<?= $required ? ' required' : '' ?>></div>
            <?php endforeach; ?>
            <div class="col-md-6"><label class="form-label" for="lib-year">Publication year</label><input class="form-control" type="number" id="lib-year" name="publication_year" min="1" max="9999" value="<?= $escape($value('publication_year')) ?>"></div>
            <div class="col-12"><label class="form-label" for="lib-description">Description</label><textarea class="form-control" id="lib-description" name="description" maxlength="6000" rows="6"><?= $escape($value('description')) ?></textarea></div>
        </div>
        <h2 class="h4 mt-4">Discoverability</h2><p>Enter one label per line, up to ten labels in each group, with at most 120 characters per label.</p>
        <div class="row g-3"><?php foreach (['categories' => 'Categories', 'subjects' => 'Subjects', 'levels' => 'Levels'] as $key => $label): ?><div class="col-md-4"><label class="form-label" for="lib-<?= $escape($key) ?>"><?= $escape($label) ?></label><textarea class="form-control" id="lib-<?= $escape($key) ?>" name="<?= $escape($key) ?>" rows="5" maxlength="1210"><?= $escape($value($key)) ?></textarea></div><?php endforeach; ?></div>
        <h2 class="h4 mt-4">Access and distribution rights</h2>
        <p>Publication requires confirmed distribution rights and a current licence. Rights expiry stops downloads. Future paid resources have no payment or download facility.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="lib-access">Access (required)</label><select class="form-select" id="lib-access" name="access" required><?php foreach (['free' => 'Free, including guests', 'restricted' => 'Restricted — grant required', 'future_paid' => 'Future paid — unavailable'] as $key => $label): ?><option value="<?= $escape($key) ?>"<?= ($value('access') ?: 'free') === $key ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="lib-expiry">Rights expire on</label><input type="date" class="form-control" id="lib-expiry" name="rights_expires_on" value="<?= $escape($value('rights_expires_on')) ?>"></div>
            <div class="col-12"><label class="form-label" for="lib-licence">Licence (required for publication)</label><input class="form-control" id="lib-licence" name="licence" maxlength="160" value="<?= $escape($value('licence')) ?>"></div>
            <div class="col-12"><label class="form-label" for="lib-rights">Private rights evidence reference (required for publication)</label><input class="form-control" id="lib-rights" name="rights_reference" maxlength="300" value="<?= $escape($value('rights_reference')) ?>"><p class="form-text">Use an internal reference to permission or licensing evidence.</p></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="rights_confirmed" value="1" id="lib-confirmed"<?= $value('rights_confirmed') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="lib-confirmed">I confirm Furoeducare has permission to distribute this material under the stated licence.</label></div></div>
        </div>
        <h2 class="h4 mt-4">Files</h2>
        <div><label class="form-label" for="lib-material">Material — PDF or EPUB, maximum 20 MiB<?= $editing ? ' (optional replacement)' : ' (required)' ?></label><input class="form-control" type="file" id="lib-material" name="material" accept=".pdf,.epub,application/pdf,application/epub+zip"<?= $editing ? '' : ' required' ?>></div>
        <div><label class="form-label" for="lib-cover">Cover — JPEG or PNG, maximum 3 MiB (optional)</label><input class="form-control" type="file" id="lib-cover" name="cover" accept=".jpg,.jpeg,.png,image/jpeg,image/png"></div>
        <?php if ($editing): ?><p>Current material: <?= $escape(strtoupper($resource['material_extension'])) ?> · <?= $escape(number_format((int) $resource['material_bytes'] / 1048576, 1)) ?> MiB. <a href="/dashboard/admin/library/file?id=<?= (int) $resource['id'] ?>">Download staff preview</a></p><?php endif; ?>
        <button class="btn button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Create draft resource' ?></button>
    </form></div>
    <?php if ($editing): ?><aside class="col-lg-4">
        <section class="form-panel mb-4"><h2 class="h4">Publication</h2><p><?= (int) ($resource['downloads'] ?? 0) ?> recorded downloads.</p><p>Each action checks the current revision. Complete rights information before publishing.</p>
            <form method="post" action="/dashboard/admin/library" class="d-flex flex-wrap gap-2">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>"><input type="hidden" name="id" value="<?= (int) $resource['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $resource['revision'] ?>">
                <?php foreach (['publish' => 'Publish', 'unpublish' => 'Unpublish', 'archive' => 'Archive', 'remove' => 'Remove from catalogue'] as $action => $label): ?><button type="submit" class="btn button button-outline" name="action" value="<?= $escape($action) ?>"<?= $resource['status'] === 'removed' ? ' disabled' : '' ?>><?= $escape($label) ?></button><?php endforeach; ?>
            </form>
        </section>
        <section class="form-panel mb-4"><h2 class="h4">Grant restricted access</h2><p>Use the recipient's registered account number. Expiry uses West Africa Time (WAT) and must be in the future, within 365 days.</p>
            <form class="auth-form" method="post" action="/dashboard/admin/library">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>"><input type="hidden" name="id" value="<?= (int) $resource['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $resource['revision'] ?>"><input type="hidden" name="action" value="grant">
                <div><label class="form-label" for="lib-recipient">Recipient account number</label><input class="form-control" type="number" id="lib-recipient" name="recipient_id" min="1" required></div>
                <div><label class="form-label" for="lib-grant-expiry">Expires at (WAT)</label><input class="form-control" type="datetime-local" id="lib-grant-expiry" name="expires_at" required></div>
                <div><label class="form-label" for="lib-grant-reason">Reason</label><textarea class="form-control" id="lib-grant-reason" name="reason" rows="3" maxlength="500" required></textarea></div>
                <button type="submit" class="btn button button-primary"<?= $resource['access'] !== 'restricted' || $resource['status'] === 'removed' ? ' disabled' : '' ?>>Grant access</button>
            </form>
        </section>
        <section class="form-panel"><h2 class="h4">Access grants</h2>
            <?php if (empty($resource['grants'])): ?><p data-empty-state>No access grants recorded.</p><?php endif; ?>
            <?php foreach ($resource['grants'] ?? [] as $grant): ?><div class="border-bottom py-3"><p class="mb-1">Account #<?= (int) $grant['user_id'] ?></p><p class="small">Expires <?= $escape($data['wat']($grant['expires_at'])) ?> (WAT)<?= !empty($grant['revoked_at']) ? ' · Revoked ' . $escape($data['wat']($grant['revoked_at'])) . ' (WAT)' : '' ?></p>
                <?php if (empty($grant['revoked_at'])): ?><form method="post" action="/dashboard/admin/library" class="auth-form">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>"><input type="hidden" name="id" value="<?= (int) $resource['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $resource['revision'] ?>"><input type="hidden" name="grant_id" value="<?= (int) $grant['id'] ?>"><input type="hidden" name="action" value="revoke">
                    <label class="form-label" for="lib-revoke-<?= (int) $grant['id'] ?>">Revocation reason</label><textarea class="form-control" id="lib-revoke-<?= (int) $grant['id'] ?>" name="reason" maxlength="500" rows="2" required></textarea><button type="submit" class="btn button button-outline">Revoke access</button>
                </form><?php endif; ?>
            </div><?php endforeach; ?>
            <?php if (($resource['grants_next'] ?? null) !== null): ?><a href="/dashboard/admin/library?id=<?= (int) $resource['id'] ?>&amp;grants_before=<?= (int) $resource['grants_next'] ?>">Older grants</a><?php endif; ?>
        </section>
    </aside><?php endif; ?></div>
</div>
