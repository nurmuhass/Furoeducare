<?php
$record = $data['record'];
$staff = $data['staff'];
$resolved = $record['status'] === 'resolved';
?>
<section class="page-band">
    <div class="site-shell">
        <a href="<?= $escape($staff ? $data['staffPath'] : $data['controls']) ?>"><?= $staff ? 'Back to privacy requests' : 'Back to privacy controls' ?></a>
        <h1 class="my-3">Privacy request #<?= (int) $record['id'] ?></h1>
        <?php if ($data['notice'] !== ''): ?>
            <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
        <?php endif; ?>
        <section class="form-panel mb-3">
            <h2><?= $escape($data['rights'][$record['right_type']]) ?></h2>
            <p><span class="status-badge"><?= $resolved ? 'Resolved' : 'Submitted' ?></span>
                · Submitted <?= $escape(substr($record['created_at'], 0, 19)) ?> UTC
                <?php if ($staff): ?>
                    · Account #<?= (int) $record['account_id'] ?>
                <?php endif; ?>
            </p>
            <p class="text-break"><?= nl2br($escape($record['details'])) ?></p>
        </section>
        <?php if ($resolved): ?>
            <section class="form-panel">
                <h2>Response</h2>
                <p>Resolved <?= $escape(substr($record['closed_at'], 0, 19)) ?> UTC</p>
                <p class="text-break"><?= nl2br($escape($record['staff_note'])) ?></p>
            </section>
        <?php elseif ($data['canResolve']): ?>
            <section class="form-panel">
                <h2>Record the outcome</h2>
                <?php if ($data['error'] !== ''): ?>
                    <p class="alert alert-danger" role="alert"><?= $escape($data['error']) ?></p>
                <?php endif; ?>
                <form method="post" action="<?= $escape($data['staffPath']) ?>">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label" for="privacy-response">Response to the requester</label>
                        <textarea class="form-control" id="privacy-response" name="staff_note" rows="4" maxlength="<?= (int) $data['responseLimit'] ?>" aria-describedby="privacy-response-help" required></textarea>
                        <p id="privacy-response-help" class="form-text">Record the actions taken and explain the outcome. Use an approved secure channel to send copies of personal data.</p>
                    </div>
                    <button class="btn button button-primary" type="submit">Mark resolved</button>
                </form>
            </section>
        <?php elseif ($staff): ?>
            <p>Another administrator must resolve this request.</p>
        <?php endif; ?>
    </div>
</section>
