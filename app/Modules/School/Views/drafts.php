<?php $draft = $data['draft']; ?>
<p class="form-description">Drafts are private to your school. You can edit them while your account is awaiting approval.</p>
<form class="auth-form" method="post" action="/dashboard/school/drafts">
    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
    <?php if (isset($draft['id'])): ?><input type="hidden" name="draft_id" value="<?= (int) $draft['id'] ?>"><?php endif; ?>
    <?php
    $field('title', 'Draft title', $draft['title'] ?? '', options: ['maxlength' => 120, 'required' => ''], error: $errors['title'] ?? '');
    $field('requirements', 'Recruitment requirements', $draft['requirements'] ?? '', 'textarea',
        ['maxlength' => 4000, 'rows' => 6], $errors['requirements'] ?? '',
        'You can include subjects, teaching levels, experience and the preferred start date.');
    ?>
    <button class="btn button button-primary" type="submit">Save private draft</button>
    <?php if (isset($draft['id'])): ?><a href="/dashboard/school?tab=drafts">Start another draft</a><?php endif; ?>
</form>
<h2 class="mt-4">Saved drafts</h2>
<?php if ($data['drafts'] === []): ?>
    <p>No drafts on this page.</p>
<?php else: ?>
    <ul>
        <?php foreach ($data['drafts'] as $item): ?>
            <li><a href="/dashboard/school?tab=drafts&amp;id=<?= (int) $item['id'] ?>"><?= $escape($item['title']) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($data['next'] !== null): ?><a href="/dashboard/school?tab=drafts&amp;before=<?= $data['next'] ?>">Older drafts</a><?php endif; ?>
