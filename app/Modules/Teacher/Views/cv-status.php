<?php

$cv = $data['cv'];
if ($cv === null) {
    return;
}
$manual = '/dashboard/teacher?section=biography';
$failure = match ($cv['failure_code']) {
    'scanned_pdf' => 'This PDF appears to be scanned or image-only. OCR is not yet supported.',
    'no_text' => 'We found no selectable text. Scanned PDFs require OCR, which is not yet supported.',
    'expired' => 'The extraction draft expired.',
    'interrupted', 'limit_exceeded' => 'CV extraction did not finish within its processing limit.',
    default => 'We could not read this CV. Try an unprotected text-based PDF or DOCX.',
};
?>
<section class="border rounded p-3 my-4" aria-labelledby="cv-extraction">
    <h3 id="cv-extraction" class="h5">CV profile extraction</h3>
    <p class="mb-2"><?= $escape($cv['display_name']) ?></p>
    <?php switch ($cv['status']):
        case 'new': ?>
            <form method="post" action="/dashboard/teacher/cv">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <input type="hidden" name="document_id" value="<?= (int) $cv['document_id'] ?>">
                <input type="hidden" name="action" value="queue">
                <button class="btn button button-outline" type="submit">Extract details from this CV</button>
            </form>
            <?php break;
        case 'queued':
        case 'processing': ?>
            <p role="status"><?= $cv['status'] === 'queued' ? 'Waiting for extraction.' : 'Extracting your CV.' ?></p>
            <a href="/dashboard/teacher?section=documents#cv-extraction">Check extraction status</a>
            <?php break;
        case 'ready': ?>
            <p>Suggestions are ready to review. The draft is kept for seven days.</p>
            <a class="btn button button-primary" href="/dashboard/teacher/cv">Review CV suggestions</a>
            <?php break;
        case 'applied': ?>
            <p class="mb-0">Review completed. You can edit the saved details in your profile.</p>
            <?php break;
        case 'expired':
        case 'failed': ?>
            <p role="status"><?= $escape($cv['status'] === 'expired' ? 'The extraction draft expired.' : $failure) ?></p>
            <p class="mb-0">Upload another CV or <a href="<?= $manual ?>">continue with manual profile editing</a>.</p>
            <?php break;
    endswitch; ?>
</section>
