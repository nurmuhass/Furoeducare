<?php
$listing = $data['listing'];
$record = $data['record'];
$submitted = $record !== null && $record['submitted_at'] !== null;
?>
<section class="page-band">
    <div class="site-shell page-intro">
        <a href="/find-teachers/profile?ref=<?= $escape($listing['reference']) ?>"><?= $escape($listing['professional_name'] ?? $listing['title']) ?></a>
        <h1 class="mt-3">Request this teacher</h1>
        <p>Ref <?= $escape($listing['reference']) ?></p>
    </div>
</section>
<section class="site-shell section-space">
    <div class="form-panel">
        <?php if ($data['notice'] !== ''): ?><p class="auth-notice mb-3" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
        <?php if ($submitted): ?>
            <h2>Request submitted</h2>
            <p class="text-break"><?= $escape($record['notes']) ?></p>
        <?php else: ?>
            <?php if (!$data['canSubmit']): ?><p>Your school can save a private draft while it waits for approval.</p><?php endif; ?>
            <form class="auth-form" method="post" action="/find-teachers/request">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <input type="hidden" name="ref" value="<?= $escape($listing['reference']) ?>">
                <div>
                    <label class="form-label" for="directory-request-notes">Describe what you need</label>
                    <p class="form-text" id="directory-request-hint">Include subjects, teaching levels or scheduling needs. Leave out personal contact details and children’s names.</p>
                    <textarea class="form-control" name="notes" id="directory-request-notes" rows="5" maxlength="1500" required
                              aria-describedby="directory-request-hint<?= isset($data['error']) ? ' directory-request-error' : '' ?>"
                              <?= isset($data['error']) ? 'aria-invalid="true"' : '' ?>><?= $escape($data['notes'] ?? $record['notes'] ?? '') ?></textarea>
                    <?php if (isset($data['error'])): ?><p class="form-error" id="directory-request-error" role="alert"><?= $escape($data['error']) ?></p><?php endif; ?>
                </div>
                <button class="btn button button-primary align-self-start" type="submit"><?= $data['canSubmit'] ? 'Send request to Furoeducare' : 'Save private draft' ?></button>
            </form>
        <?php endif; ?>
        <p class="form-next"><a href="/find-teachers/requests">My teacher requests</a></p>
    </div>
</section>
