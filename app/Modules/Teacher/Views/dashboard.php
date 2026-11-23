<?php

use Furoeducare\Modules\Teacher\DashboardPage;
use Furoeducare\Modules\Teacher\Section;

$section = $data['section'];
$missing = $data['missing'];
$errors = $data['errors'];
$values = $data['values'];

require __DIR__ . '/fields.php';
?>
<div class="<?= $tab === DashboardPage::Profile ? 'teacher-profile-layout' : 'teacher-form-layout' ?>">
    <?php if ($tab === DashboardPage::Profile): ?>
        <aside class="teacher-card teacher-profile-navigation">
            <h2>Profile sections</h2>
            <p class="teacher-muted"><?= $complete ?> of <?= $total ?> sections complete</p>
            <nav aria-label="Professional profile sections">
                <ul>
                    <?php foreach (Section::cases() as $item): ?>
                        <?php $link = DashboardPage::forSection($item) === DashboardPage::Profile
                            ? '/dashboard/teacher?section=' . $item->value : DashboardPage::forSection($item)->url(); ?>
                        <li>
                            <a href="<?= $escape($link) ?>" <?= $item === $section ? 'aria-current="page"' : '' ?>>
                                <span><?= $escape($item->label()) ?></span>
                                <span class="teacher-section-status <?= $missing[$item->value] === [] ? 'is-complete' : '' ?>">
                                    <?= $missing[$item->value] === [] ? 'Complete' : 'Incomplete' ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </aside>
    <?php endif; ?>

    <section class="form-panel teacher-editor" aria-labelledby="teacher-section">
        <h2 id="teacher-section"><?= $escape(match ($section) { Section::Availability => 'Teaching and tutoring availability', Section::Documents => 'Upload a document', default => $section->label() }) ?></h2>
        <?php if ($tab !== DashboardPage::Profile): ?>
            <p class="teacher-muted teacher-editor-progress"><?= $complete ?> of <?= $total ?> sections complete</p>
        <?php endif; ?>
        <?php if ($missing[$section->value] !== []): ?>
            <div class="teacher-missing">
                <h3>Missing information</h3>
                <ul><?php foreach ($missing[$section->value] as $item): ?><li><?= $escape($item) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <?= \Furoeducare\UI\Components::error($errors) ?>
        <?php if ($section === Section::Documents): ?>
            <?php require __DIR__ . '/documents.php'; ?>
        <?php else: ?>
            <form class="auth-form" action="/dashboard/teacher/profile" method="post">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <input type="hidden" name="section" value="<?= $section->value ?>">
                <?php if ($section === Section::Availability): ?>
                    <?php require __DIR__ . '/availability.php'; ?>
                <?php else: ?>
                    <input type="hidden" name="list_style" value="lines">
                    <?php require __DIR__ . '/profile-section.php'; ?>
                <?php endif; ?>
                <button class="btn button button-primary" type="submit"><?= $section === Section::Availability ? 'Save availability' : 'Save section' ?></button>
            </form>
        <?php endif; ?>
    </section>
</div>
