<?php

use Furoeducare\Modules\Teacher\TeacherDocuments;

$doc = $data['document'];
$id = (int) $doc['id'];
?>
<section class="page-band">
    <div class="site-shell">
        <a href="<?= $escape($data['root']) ?>/documents">Document review queue</a>
        <h1 class="my-3 text-break"><?= $escape($doc['display_name']) ?></h1>
        <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
        <div class="form-panel">
            <dl>
                <dt>Teacher</dt>
                <dd><a href="<?= $escape($data['root']) ?>/teachers?id=<?= (int) $doc['teacher_id'] ?>"><?= $escape($doc['first_name'] . ' ' . $doc['last_name']) ?></a></dd>
                <dt>Document type</dt><dd><?= $escape(TeacherDocuments::KINDS[$doc['kind']]) ?></dd>
                <dt>Review status</dt><dd><?= $escape(ucfirst($doc['decision'] ?? 'awaiting review')) ?></dd>
            </dl>
            <p><a href="<?= $escape($data['root']) ?>/documents/download?id=<?= $id ?>">Download private document</a></p>
            <?php if ($data['error'] !== ''): ?><p class="form-summary" role="alert"><?= $escape($data['error']) ?></p><?php endif; ?>
            <?php if ($doc['decision'] !== null): ?>
                <h2>Review notes</h2>
                <p class="text-break"><?= $escape($doc['reason']) ?></p>
                <small><?= $escape($doc['reviewer_email']) ?> · <?= $escape(substr($doc['reviewed_at'], 0, 16)) ?> UTC</small>
            <?php elseif ($data['selfReview']): ?>
                <p>Another administrator must review this document.</p>
            <?php else: ?>
                <form class="auth-form" method="post" action="<?= $escape($data['root']) ?>/documents/review">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <label for="document-decision">Decision</label>
                    <select class="form-select" name="decision" id="document-decision" required>
                        <option value="">Choose a decision</option>
                        <option value="accepted" <?= $data['decision'] === 'accepted' ? 'selected' : '' ?>>Accept document</option>
                        <option value="rejected" <?= $data['decision'] === 'rejected' ? 'selected' : '' ?>>Reject document</option>
                    </select>
                    <label for="document-reason">Decision notes shared with the teacher</label>
                    <textarea class="form-control" name="reason" id="document-reason" rows="4" maxlength="1500" required><?= $escape($data['reason']) ?></textarea>
                    <button class="btn button button-primary" type="submit">Record document review</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
