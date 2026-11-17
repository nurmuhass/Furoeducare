<?php

use Furoeducare\Modules\Teacher\ProfileCompletion;
use Furoeducare\Modules\Teacher\TeacherDocuments;
use Furoeducare\Modules\Verification\ReviewInput;
use Furoeducare\Modules\Verification\TeacherStatus;

$profile = $data['profile'];
$sections = $profile['sections'];
$status = TeacherStatus::from($profile['verification_status']);
$id = (int) $profile['user_id'];
$missing = array_merge(...array_values(ProfileCompletion::missing($profile)));
$missing = array_values(array_filter($missing));
$display = [
    'Category' => $profile['category'] === 'nysc' ? 'NYSC Member' : 'Teacher',
    'Email' => $profile['email'],
    'Phone' => $profile['phone'],
    'Biography' => $sections['biography']['bio'] ?? '',
    'Subjects' => implode(', ', $sections['subjects']['subjects'] ?? []),
    'Teaching levels' => implode(', ', $sections['subjects']['levels'] ?? []),
    'Skills' => implode(', ', $sections['skills']['skills'] ?? []),
    'Certifications' => implode(', ', $sections['skills']['certifications'] ?? []),
];
$nysc = $sections['personal']['nysc'] ?? [];
foreach (['state' => 'NYSC state', 'batch' => 'NYSC batch', 'year' => 'NYSC service year'] as $key => $label) {
    if (isset($nysc[$key]) && $nysc[$key] !== '') {
        $display[$label] = (string) $nysc[$key];
    }
}
$checkLabels = ReviewInput::TEACHER_CHECKS;
$checked = $data['checks'];
?>
<section class="page-band">
    <div class="site-shell">
        <a href="<?= $escape($data['root']) ?>/teachers">Teacher verification queue</a>
        <h1 class="my-3"><?= $escape($profile['first_name'] . ' ' . $profile['last_name']) ?></h1>
        <p><span class="status-badge"><?= $escape($status->label()) ?></span></p>
        <p><a href="/dashboard/admin/directory?id=<?= $id ?>">Manage public listing</a></p>
        <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
        <div class="row g-4 align-items-start">
            <section class="col-lg-6">
                <div class="form-panel">
                    <h2>Professional profile</h2>
                    <p><?= $profile['email_verified_at'] === null ? 'Email verification outstanding' : 'Email verified' ?></p>
                    <?php if ($profile['suspended_at'] !== null): ?><p>Sign-in is suspended.</p><?php endif; ?>
                    <dl>
                        <?php foreach ($display as $label => $value): ?>
                            <dt><?= $escape($label) ?></dt><dd class="text-break"><?= $escape($value === '' ? 'Missing' : $value) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                    <h3>Qualifications</h3>
                    <?php if ($sections['qualifications']['rows'] === []): ?><p>No qualifications supplied.</p><?php endif; ?>
                    <ul>
                        <?php foreach ($sections['qualifications']['rows'] as $row): ?>
                            <li><?= $escape(implode(' · ', array_filter([$row['qualification'], $row['institution'],
                                $row['field_of_study'], $row['completed_year']]))) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <h3>Work and teaching experience</h3>
                    <?php if (!empty($sections['experience']['no_experience'])): ?><p>Teacher has declared no experience.</p>
                    <?php elseif ($sections['experience']['rows'] === []): ?><p>No experience supplied.</p><?php endif; ?>
                    <ul>
                        <?php foreach ($sections['experience']['rows'] as $row): ?>
                            <li>
                                <?= $escape(implode(' · ', array_filter([$row['position_title'], $row['organisation'],
                                    $row['started_year'], $row['ended_year']]))) ?>
                                <?php if ($row['description']): ?><p class="text-break"><?= $escape($row['description']) ?></p><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <h3>Documents</h3>
                    <?php if ($sections['documents']['files'] === []): ?><p>No documents on file.</p><?php endif; ?>
                    <ul>
                        <?php foreach ($sections['documents']['files'] as $file): ?>
                            <li>
                                <a href="<?= $escape($data['root']) ?>/documents?id=<?= (int) $file['id'] ?>"><?= $escape($file['display_name']) ?></a>
                                · <?= $escape(TeacherDocuments::KINDS[$file['kind']]) ?>
                                · <?= $escape(ucfirst($file['decision'] ?? 'awaiting review')) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($data['removedDocumentReviews'] !== []): ?>
                        <h3>Decisions on removed documents</h3>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($data['removedDocumentReviews'] as $entry): ?>
                                <li class="list-group-item">
                                    <strong><?= $escape(TeacherDocuments::KINDS[$entry['kind']]) ?> · <?= $escape(ucfirst($entry['decision'])) ?></strong>
                                    <p class="text-break"><?= $escape($entry['reason']) ?></p>
                                    <small><?= $escape($entry['reviewer_email']) ?> · <?= $escape(substr($entry['created_at'], 0, 16)) ?> UTC</small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
            <section class="col-lg-6">
                <div class="form-panel">
                    <h2>Record a decision</h2>
                    <?php if ($missing !== []): ?>
                        <p>Profile requirements still outstanding</p>
                        <ul><?php foreach ($missing as $item): ?><li><?= $escape($item) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if ($data['error'] !== ''): ?><p class="form-summary" role="alert"><?= $escape($data['error']) ?></p><?php endif; ?>
                    <?php if ($data['selfReview']): ?>
                        <p>Another administrator must review this profile.</p>
                    <?php else: ?>
                        <form class="auth-form" method="post" action="<?= $escape($data['root']) ?>/teachers/review">
                            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="revision" value="<?= (int) $profile['verification_revision'] ?>">
                            <label for="teacher-decision">Decision</label>
                            <select class="form-select" name="decision" id="teacher-decision" required>
                                <option value="">Choose a decision</option>
                                <?php foreach ($status->staffDecisions() as $decision): ?>
                                    <option value="<?= $escape($decision->value) ?>" <?= $data['decision'] === $decision->value ? 'selected' : '' ?>>
                                        <?= $escape($decision === TeacherStatus::Pending ? 'Reopen verification' : $decision->label()) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php require __DIR__ . '/checks.php'; ?>
                            <label for="teacher-reason">Decision notes shared with the teacher</label>
                            <textarea class="form-control" name="reason" id="teacher-reason" rows="4" maxlength="1500" required><?= $escape($data['reason']) ?></textarea>
                            <button class="btn button button-primary" type="submit">Record decision</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
            <section class="col-12">
                <div class="form-panel">
                    <h2>Review history</h2>
                    <?php if ($data['history'] === []): ?><p>No decisions recorded.</p><?php endif; ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($data['history'] as $entry): ?>
                            <li class="list-group-item">
                                <strong><?= $escape(TeacherStatus::from($entry['decision'])->label()) ?></strong>
                                <p class="text-break"><?= $escape($entry['reason']) ?></p>
                                <?php $recorded = json_decode($entry['checks'], true, flags: JSON_THROW_ON_ERROR); ?>
                                <ul>
                                    <?php foreach ($checkLabels as $key => $label): ?>
                                        <li><?= $escape($label) ?> · <?= ($recorded[$key] ?? false) ? 'Confirmed' : 'Unconfirmed' ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <small><?= $escape($entry['reviewer_email']) ?> · <?= $escape(substr($entry['created_at'], 0, 16)) ?> UTC</small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </div>
    </div>
</section>
