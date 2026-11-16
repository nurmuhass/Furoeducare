<?php

use Furoeducare\Modules\Teacher\Section;

$section = $data['section'];
$sections = Section::cases();
$missing = $data['missing'];
$complete = count(array_filter($missing, static fn (array $items): bool => $items === []));
$count = count($sections);
$percent = (int) round(100 * $complete / $count);
$errors = $data['errors'];
$values = $data['values'];

require __DIR__ . '/fields.php';
?>
<section class="page-band">
    <div class="site-shell">
        <h1>Teacher onboarding</h1>
        <p>Build your private professional profile one section at a time.</p>
        <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>

        <div class="row g-4 align-items-start mt-1">
            <aside class="col-lg-4">
                <div class="form-panel">
                    <h2 class="h4">Profile completion</h2>
                    <p class="mb-2"><?= $complete ?> of <?= $count ?> sections complete · <?= $percent ?>%</p>
                    <div class="progress mb-4" role="progressbar" aria-label="Profile completion" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?= $percent ?>%"></div>
                    </div>
                    <nav aria-label="Profile sections">
                        <ul class="nav nav-pills flex-column gap-1">
                            <?php foreach ($sections as $item): ?>
                                <li class="nav-item">
                                    <a href="/dashboard/teacher?section=<?= $item->value ?>" class="nav-link d-flex justify-content-between align-items-center gap-2 <?= $item === $section ? 'active' : '' ?>"
                                        <?= $item === $section ? 'aria-current="page"' : '' ?>>
                                        <span><?= $escape($item->label()) ?></span>
                                        <span class="badge rounded-pill text-bg-light"><?= $missing[$item->value] === [] ? 'Complete' : 'Incomplete' ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                    <?php if ($complete < $count): ?>
                        <details class="mt-3">
                            <summary>View missing information</summary>
                            <ul class="mt-2 mb-0">
                                <?php foreach ($sections as $item): ?>
                                    <?php if ($missing[$item->value] !== []): ?>
                                        <li><a href="/dashboard/teacher?section=<?= $item->value ?>"><?= $escape($item->label()) ?></a></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php else: ?>
                        <p class="mt-3 mb-0">Your profile is complete and remains private.</p>
                    <?php endif; ?>
                    <?php if (count($data['roleLinks']) > 1): ?>
                        <ul class="mt-3 mb-0">
                            <?php foreach ($data['roleLinks'] as $role): ?>
                                <?php if ($role !== \Furoeducare\Modules\Auth\Role::Teacher): ?>
                                    <li><a href="<?= $escape($role->dashboard()) ?>"><?= $escape($role->label()) ?> dashboard</a></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </aside>
            <div class="col-lg-8">
                <section class="form-panel" aria-labelledby="teacher-section">
                    <h2 id="teacher-section"><?= $escape($section->label()) ?></h2>
                    <?php if ($missing[$section->value] !== []): ?>
                        <div class="mb-3">
                            <h3 class="h6">Missing information</h3>
                            <ul class="mb-0"><?php foreach ($missing[$section->value] as $item): ?><li><?= $escape($item) ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($errors !== []): ?>
                        <p class="form-summary" role="alert">Review the highlighted fields and try again.</p>
                    <?php endif; ?>

                    <?php if ($section === Section::Documents): ?>
                        <?php require __DIR__ . '/documents.php'; ?>
                    <?php else: ?>
                        <form class="auth-form" action="/dashboard/teacher/profile" method="post">
                            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                            <input type="hidden" name="section" value="<?= $section->value ?>">
                            <?php require __DIR__ . '/profile-section.php'; ?>
                            <button class="btn button button-primary align-self-start" type="submit">Save section</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</section>
