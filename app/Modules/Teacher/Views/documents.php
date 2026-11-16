<?php

use Furoeducare\Modules\Teacher\TeacherDocuments;

$documents = $data['profile']['sections']['documents']['files'];
?>
<p>Upload your CV as a PDF or DOCX. A new CV replaces the one you have on file. You can also add supporting professional documents.</p>
<p class="form-next">Supporting documents can be PDF, DOCX, JPEG or PNG. Each file can be up to 5 MB. You can store up to <?= TeacherDocuments::SUPPORTING_LIMIT ?> supporting documents. Leave out identity documents.</p>
<form method="post" action="/dashboard/teacher/documents/upload" enctype="multipart/form-data" class="auth-form">
    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
    <?php
    $field('kind', 'Document type', $values['kind'] ?? '', 'select', TeacherDocuments::KINDS, required: true);
    $field('document', 'Choose a file', type: 'file',
        options: ['accept' => '.pdf,.docx,.jpeg,.jpg,.png', 'required' => ''], error: $errors['document'] ?? '');
    ?>
    <button class="btn button button-primary align-self-start" type="submit">Upload document</button>
</form>

<h3 class="h5 mt-4">Saved documents</h3>
<?php if ($documents === []): ?>
    <p class="mb-0">You haven’t uploaded any documents yet.</p>
<?php else: ?>
    <ul class="list-group">
        <?php foreach ($documents as $document): ?>
            <li class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="text-break">
                    <a href="/dashboard/teacher/documents/download?id=<?= (int) $document['id'] ?>"><?= $escape($document['display_name']) ?></a>
                    <small class="d-block"><?= $escape(TeacherDocuments::KINDS[$document['kind']]) ?> · <?= (int) ceil($document['bytes'] / 1024) ?> KB</small>
                </div>
                <form method="post" action="/dashboard/teacher/documents/remove">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $document['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Remove <?= $escape($document['display_name']) ?>">Remove</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
