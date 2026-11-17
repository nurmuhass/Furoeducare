<?php
use Furoeducare\Modules\Parent\TutoringInput;
use Furoeducare\Modules\Parent\TutoringStatus;
use Furoeducare\Support\NigerianStates;

$record = $data['record'] ?? null;
?>
<?php if ($tab === 'request-teacher'): ?>
    <?php
    $details = $record['details'] ?? [];
    $reference = $record['selected_teacher_reference'] ?? null;
    $subject = ($record['legacy_directory_id'] ?? null) !== null && $details === [] ? '' : ($record['subject'] ?? '');
    ?>
    <?php if ($reference === null): ?>
        <p><a href="/find-teachers">Choose a particular teacher in Find Teachers</a>, or use this form to ask Furoeducare to find a tutor.</p>
    <?php else: ?>
        <p>Requested teacher <strong><?= $escape($reference) ?></strong></p>
    <?php endif; ?>
    <p class="form-description">Save a private draft and review it before submitting. Leave out learner names, dates of birth, school names, contact details and street addresses.</p>
    <form class="auth-form" method="post" action="/dashboard/parent/requests">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <?php if ($reference !== null): ?><input type="hidden" name="ref" value="<?= $escape($reference) ?>"><?php endif; ?>
        <?php if (isset($record['id'])): ?>
            <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
            <input type="hidden" name="version" value="<?= $escape($record['updated_at']) ?>">
            <?php if ($reference !== null): ?>
                <label class="form-check-label">
                    <input class="form-check-input" type="checkbox" name="general_request" value="1" <?= ($data['clearReference'] ?? false) ? 'checked' : '' ?>>
                    Continue as a general request and keep these lesson details
                </label>
            <?php endif; ?>
        <?php endif; ?>
        <?php $field('subject', 'Subjects or learning areas', $subject, options: ['maxlength' => 120], error: $errors['subject'] ?? ''); ?>
        <div class="row g-3">
            <div class="col-md-6"><?php $field('level', 'Student education level', $details['level'] ?? '', 'select', TutoringInput::LEVELS, $errors['level'] ?? ''); ?></div>
            <div class="col-md-6"><?php $field('learners', 'Number of learners', $details['learners'] ?? '', 'number', ['min' => 1, 'max' => 30], $errors['learners'] ?? ''); ?></div>
        </div>
        <?php $field('objectives', 'Learning objectives', $details['objectives'] ?? '', 'textarea', ['maxlength' => 2000, 'rows' => 3], $errors['objectives'] ?? ''); ?>
        <?php $field('mode', 'Lesson format', $details['mode'] ?? '', 'select', TutoringInput::MODES, $errors['mode'] ?? ''); ?>
        <div class="row g-3">
            <div class="col-md-6"><?php $field('state_code', 'Preferred state or FCT', $details['state_code'] ?? '', 'select', NigerianStates::NAMES, $errors['state_code'] ?? ''); ?></div>
            <div class="col-md-6"><?php $field('city', 'Preferred city or general area', $details['city'] ?? '', options: ['maxlength' => 100],
                error: $errors['city'] ?? '', hint: 'Use a city or general area for home lessons.'); ?></div>
        </div>
        <fieldset>
            <legend class="form-label">Preferred lesson days</legend>
            <div class="d-flex flex-wrap gap-3">
                <?php foreach (TutoringInput::DAYS as $key => $label): ?>
                    <label class="form-check-label">
                        <input class="form-check-input" type="checkbox" name="days[]" value="<?= $escape($key) ?>"
                            <?= in_array($key, $details['days'] ?? [], true) ? 'checked' : '' ?>> <?= $escape($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if (isset($errors['days'])): ?><p class="field-error" role="alert"><?= $escape($errors['days']) ?></p><?php endif; ?>
        </fieldset>
        <fieldset>
            <legend class="form-label">Preferred times in Nigeria (WAT)</legend>
            <p class="form-next">Enter one to three ranges that work on the days selected.</p>
            <?php for ($i = 0; $i < 3; $i++): ?>
                <?php $range = $details['times'][$i] ?? ['from' => '', 'to' => '']; ?>
                <div class="row g-3 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label" for="parent-from-<?= $i ?>">Start time <?= $i + 1 ?></label>
                        <input class="form-control" type="time" id="parent-from-<?= $i ?>" name="time_from[]" value="<?= $escape($range['from']) ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="parent-to-<?= $i ?>">End time <?= $i + 1 ?></label>
                        <input class="form-control" type="time" id="parent-to-<?= $i ?>" name="time_to[]" value="<?= $escape($range['to']) ?>">
                    </div>
                </div>
            <?php endfor; ?>
            <?php if (isset($errors['times'])): ?><p class="field-error" role="alert"><?= $escape($errors['times']) ?></p><?php endif; ?>
        </fieldset>
        <?php $field('sessions', 'Sessions per week', $details['sessions'] ?? '', 'number', ['min' => 1, 'max' => 14], $errors['sessions'] ?? ''); ?>
        <?php $field('qualifications', 'Required qualifications or experience (optional)', $details['qualifications'] ?? '', options: ['maxlength' => 400], error: $errors['qualifications'] ?? ''); ?>
        <?php $field('requirements', 'Additional requirements (optional)', $record['requirements'] ?? '', 'textarea',
            ['maxlength' => 2000, 'rows' => 3], $errors['requirements'] ?? ''); ?>
        <button class="btn button button-primary" type="submit">Save private draft</button>
    </form>
<?php elseif ($record !== null): ?>
    <?php $stage = TutoringStatus::from($record['status']); ?>
    <a href="/dashboard/parent?tab=requests">Back to My Requests</a>
    <h2 class="mt-3 text-break"><?= $escape($record['subject'] ?: 'Untitled tutoring draft') ?></h2>
    <p><span class="status-badge"><?= $escape($stage->label()) ?></span></p>
    <?php require __DIR__ . '/tutoring-details.php'; ?>
    <?php if ($stage === TutoringStatus::Draft): ?>
        <?php if ($data['missing'] !== []): ?>
            <p>Add <?= $escape(implode(', ', $data['missing'])) ?> before submitting.</p>
        <?php endif; ?>
        <div class="button-row mt-3">
            <a class="btn button button-outline" href="/dashboard/parent?tab=request-teacher&amp;id=<?= (int) $record['id'] ?>">Edit draft</a>
            <?php if ($data['missing'] === []): ?>
                <form method="post" action="/dashboard/parent/requests/submit">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                    <input type="hidden" name="version" value="<?= $escape($record['updated_at']) ?>">
                    <button class="btn button button-primary" type="submit">Submit request</button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if ($data['candidates'] !== []): ?>
            <h3 class="h5 mt-4">Shortlisted tutors</h3>
            <ul>
                <?php foreach ($data['candidates'] as $candidate): ?>
                    <li><a href="/find-teachers/profile?ref=<?= $escape($candidate['reference']) ?>"><?= $escape($candidate['title']) ?></a>
                        <span><?= $escape($candidate['reference']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php $history = $data['history']; require __DIR__ . '/tutoring-history.php'; ?>
    <?php endif; ?>
<?php else: ?>
    <?php if ($tab !== 'arrangements'): ?>
        <div class="button-row mb-3">
            <a class="btn button button-primary" href="/dashboard/parent?tab=request-teacher">Start a general request</a>
            <a class="btn button button-outline" href="/find-teachers">Choose a teacher</a>
        </div>
    <?php endif; ?>
    <?php if ($data['requests'] === []): ?>
        <p><?= $tab === 'arrangements' ? 'No confirmed lesson arrangements yet.' : 'No tutoring requests on this page.' ?></p>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($data['requests'] as $item): ?>
                <li class="list-group-item d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <a class="text-break" href="/dashboard/parent?tab=requests&amp;id=<?= (int) $item['id'] ?>"><?= $escape($item['subject'] ?: 'Untitled tutoring draft') ?></a>
                    <span class="status-badge"><?= $escape(TutoringStatus::from($item['status'])->label()) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($data['next'] !== null): ?><p class="mt-3"><a href="/dashboard/parent?tab=<?= $escape($tab) ?>&amp;before=<?= (int) $data['next'] ?>">Older requests</a></p><?php endif; ?>
<?php endif; ?>
