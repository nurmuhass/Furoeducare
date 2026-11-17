<?php if ($data['disclosures'] === []): ?>
    <p>There are no disclosure requests for this case.</p>
<?php else: ?>
    <ul class="list-unstyled">
        <?php foreach ($data['disclosures'] as $disclosure): ?>
            <?php
            $grantId = (int) $disclosure['grant_id'];
            $expiresAt = $disclosure['expires_at'];
            $revoked = $disclosure['revoked_at'] !== null;
            $expired = $expiresAt !== null && !(bool) $disclosure['unexpired'];
            $available = $grantId > 0 && $disclosure['status'] === 'approved'
                && $policyActive && !$closed && !$revoked && $expiresAt !== null && !$expired;
            $status = match (true) {
                $revoked => 'Revoked',
                $expired => 'Expired',
                default => ucfirst($disclosure['status']),
            };
            ?>
            <li class="border-bottom py-3">
                <h3 class="h5 mb-2"><?= $escape($scopeNames[$disclosure['scope']]) ?> · <?= $escape($disclosure['reference']) ?></h3>
                <p class="mb-2">
                    <span class="status-badge"><?= $escape($status) ?></span>
                    <?php if ($expiresAt !== null): ?>
                        <span class="ms-2">Expiry <?= $escape(substr($expiresAt, 0, 16)) ?> UTC</span>
                    <?php endif; ?>
                </p>
                <?php if ($staff): ?>
                    <?php if ($disclosure['status'] === 'pending' && $data['assignedToViewer']): ?>
                        <a href="<?= $escape($casePath) ?>&amp;disclosure=<?= (int) $disclosure['id'] ?>#candidate-disclosure-review">
                            Review request
                        </a>
                    <?php endif; ?>
                    <?php if (($disclosure['reason'] ?? '') !== ''): ?>
                        <p class="directory-request-notes text-break mb-2"><?= $escape($disclosure['reason']) ?></p>
                    <?php endif; ?>
                    <?php if (($disclosure['evidence_reference'] ?? '') !== ''): ?>
                        <p class="text-break mb-2">Consent evidence <?= $escape($disclosure['evidence_reference']) ?></p>
                    <?php endif; ?>
                    <?php if ($grantId > 0 && !$revoked && $data['assignedToViewer']): ?>
                        <form class="auth-form" method="post" action="<?= $escape($root) ?>">
                            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                            <input type="hidden" name="id" value="<?= $caseId ?>">
                            <input type="hidden" name="grant_id" value="<?= $grantId ?>">
                            <div>
                                <label class="form-label" for="candidate-revoke-<?= $grantId ?>">Revocation reason</label>
                                <input class="form-control" id="candidate-revoke-<?= $grantId ?>" name="reason" maxlength="1000" required>
                            </div>
                            <button class="btn button button-outline" name="action" value="revoke" type="submit">Revoke access</button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($available && $disclosure['scope'] === 'professional'): ?>
                    <a class="btn button button-primary" href="<?= $escape($root) ?>/profile?id=<?= $grantId ?>">
                        Open approved professional profile
                    </a>
                <?php elseif ($available && $disclosure['scope'] === 'original_cv'): ?>
                    <form method="post" action="<?= $escape($root) ?>/download">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                        <input type="hidden" name="id" value="<?= $grantId ?>">
                        <button class="btn button button-primary" type="submit">Download approved CV</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
