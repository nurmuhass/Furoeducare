<?php $heading = isset($selection) ? 'h4' : 'h2'; ?>
<?php if (($profile['qualifications'] ?? []) !== []): ?>
    <section class="mb-4" aria-labelledby="candidate-profile-qualifications">
        <<?= $heading ?> id="candidate-profile-qualifications">Qualifications</<?= $heading ?>>
        <ul class="list-unstyled">
            <?php foreach ($profile['qualifications'] as $qualification): ?>
                <li class="py-2">
                    <strong><?= $escape($qualification['qualification']) ?></strong>
                    <?php if (($qualification['field_of_study'] ?? '') !== ''): ?>
                        <span class="ms-1"><?= $escape((string) $qualification['field_of_study']) ?></span>
                    <?php endif; ?>
                    <?php if (isset($qualification['completed_year'])): ?>
                        <span class="ms-1"><?= $escape((string) $qualification['completed_year']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
<?php if (($profile['experience'] ?? []) !== []): ?>
    <section aria-labelledby="candidate-profile-experience">
        <<?= $heading ?> id="candidate-profile-experience">Experience</<?= $heading ?>>
        <ul class="list-unstyled">
            <?php foreach ($profile['experience'] as $experience): ?>
                <li class="py-2">
                    <strong><?= $escape($experience['position_title']) ?></strong>
                    <?php if (isset($experience['started_year'])): ?>
                        <span class="ms-1"><?= $escape((string) $experience['started_year']) ?> to <?= $escape((string) ($experience['ended_year'] ?? 'present')) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
<?php if (($profile['qualifications'] ?? []) === [] && ($profile['experience'] ?? []) === []): ?>
    <p>There are no qualifications or experience to show.</p>
<?php endif; ?>
