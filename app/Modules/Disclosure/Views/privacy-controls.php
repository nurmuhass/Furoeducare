<?php
$controls = $data['controls'];
$policy = $data['policy'];
$paused = $data['pausedAt'] !== null;
$pageSize = $data['pageSize'];
?>
<section class="page-band">
    <div class="site-shell">
        <a href="/dashboard">Back to dashboard</a>
        <h1 class="my-3">Privacy controls</h1>
        <?php if ($data['notice'] !== ''): ?>
            <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
        <?php endif; ?>
        <p>
            <a href="/privacy-policy">Privacy policy</a>
            <?php if ($policy->contact !== ''): ?>
                · <a href="<?= $escape($policy->contactUrl()) ?>">Contact the privacy team</a>
            <?php endif; ?>
        </p>

        <?php if ($data['teacher']): ?>
            <section class="form-panel mb-3" aria-labelledby="disclosure-heading">
                <h2 id="disclosure-heading">Managed disclosures</h2>
                <?php if (!$policy->active()): ?>
                    <p>Managed disclosures are currently unavailable.</p>
                <?php endif; ?>
                <?php if ($paused): ?>
                    <p>You paused disclosures on <?= $escape(substr($data['pausedAt'], 0, 19)) ?> UTC. Resuming allows new case-specific approvals. Grants that ended stay closed.</p>
                <?php endif; ?>
                <p>Pausing ends current grants and blocks new ones. Recipients may still hold information they previously viewed or downloaded. You can ask us to follow up by submitting a privacy request below.</p>
                <form method="post" action="<?= $escape($controls) ?>">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <button class="btn button button-outline" type="submit" name="action" value="<?= $paused ? 'resume' : 'pause' ?>">
                        <?= $paused ? 'Resume disclosures' : 'Pause disclosures' ?>
                    </button>
                </form>
                <h3 class="mt-4">Disclosure history</h3>
                <?php if ($data['grants'] === []): ?>
                    <p>No disclosures on this page.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach (array_slice($data['grants'], 0, $pageSize) as $grant): ?>
                            <?php
                            $status = match (true) {
                                $grant['revoked_at'] !== null => 'Revoked',
                                (bool) $grant['expired'] => 'Expired',
                                !$policy->active() || $grant['policy_version'] !== $policy->version || $paused => 'Unavailable',
                                default => 'Within access period',
                            };
                            ?>
                            <li class="list-group-item">
                                <p class="mb-1"><strong><?= $grant['scope'] === 'original_cv' ? 'Original CV' : 'Professional profile' ?></strong>
                                    · <?= $grant['requester_role'] === 'school' ? 'School' : 'Parent or guardian' ?>
                                    · <?= $escape($status) ?></p>
                                <p class="mb-1 text-break"><?= $escape($grant['purpose']) ?></p>
                                <p class="mb-0 small"><?= $escape($grant['reference']) ?> · Issued <?= $escape(substr($grant['created_at'], 0, 19)) ?> UTC
                                    <?php if ($grant['revoked_at'] !== null): ?>
                                        · Revoked <?= $escape(substr($grant['revoked_at'], 0, 19)) ?> UTC
                                    <?php endif; ?>
                                    · Expiry <?= $escape(substr($grant['expires_at'], 0, 19)) ?> UTC</p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (count($data['grants']) > $pageSize): ?>
                        <a href="<?= $escape($controls) ?>?before_grant=<?= (int) $data['grants'][$pageSize - 1]['id'] ?>#disclosure-heading">Older disclosures</a>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="form-panel mb-3" aria-labelledby="new-privacy-request">
            <h2 id="new-privacy-request">Submit a privacy request</h2>
            <?php if ($data['error'] !== ''): ?>
                <p class="alert alert-danger" role="alert"><?= $escape($data['error']) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= $escape($controls) ?>">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <input type="hidden" name="action" value="submit">
                <div class="mb-3">
                    <label class="form-label" for="privacy-right">Request type</label>
                    <select class="form-select" id="privacy-right" name="right_type" required>
                        <option value="">Select a request type</option>
                        <?php foreach ($data['rights'] as $value => $label): ?>
                            <option value="<?= $escape($value) ?>" <?= $data['type'] === $value ? ' selected' : '' ?>><?= $escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="privacy-details">Request details</label>
                    <textarea class="form-control" id="privacy-details" name="details" rows="4" maxlength="<?= (int) $data['detailLimit'] ?>" aria-describedby="privacy-help" required><?= $escape($data['details']) ?></textarea>
                    <p id="privacy-help" class="form-text">Explain what you want us to do. Leave out passwords, identity document numbers and information about other people.</p>
                </div>
                <button class="btn button button-primary" type="submit">Submit request</button>
            </form>
        </section>

        <section class="form-panel" aria-labelledby="my-privacy-requests">
            <h2 id="my-privacy-requests">My privacy requests</h2>
            <?php $requestBase = $controls; require __DIR__ . '/privacy-request-list.php'; ?>
            <?php if (count($data['requests']) > $pageSize): ?>
                <a href="<?= $escape($controls) ?>?before_request=<?= (int) $data['requests'][$pageSize - 1]['id'] ?>#my-privacy-requests">Older requests</a>
            <?php endif; ?>
        </section>
    </div>
</section>
