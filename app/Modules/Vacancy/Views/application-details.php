<section aria-label="Submitted professional profile">
    <h2 class="h4">Application information</h2>
    <p>Candidate reference <strong><?= $escape($application['candidate_reference']) ?></strong></p>
    <?php if ($application['application_statement'] !== ''): ?>
        <h3 class="h6">Application statement</h3>
        <p class="text-break"><?= nl2br($escape($application['application_statement'])) ?></p>
    <?php endif; ?>
    <?php if ($application['profile'] === null): ?>
        <p>This application was submitted before professional profile snapshots were available.</p>
    <?php else: ?>
        <?php $submittedProfile = $application['profile']; ?>
        <dl class="row">
            <dt class="col-sm-5">Account category</dt><dd class="col-sm-7"><?= $submittedProfile['category'] === 'nysc' ? 'NYSC Member' : 'Teacher' ?></dd>
            <?php foreach (['subjects' => 'Subjects', 'levels' => 'Teaching levels', 'skills' => 'Skills', 'certifications' => 'Certifications'] as $key => $label): ?>
                <?php if ($submittedProfile[$key] !== []): ?>
                    <dt class="col-sm-5"><?= $escape($label) ?></dt>
                    <dd class="col-sm-7 text-break"><?= $escape(implode(', ', $submittedProfile[$key])) ?></dd>
                <?php endif; ?>
            <?php endforeach; ?>
        </dl>
        <h3 class="h6">Qualifications</h3>
        <ul>
            <?php foreach ($submittedProfile['qualifications'] as $qualification): ?>
                <li class="text-break"><?= $escape(implode(' · ', array_filter([
                    $qualification['qualification'], $qualification['institution'], $qualification['field_of_study'],
                    (string) ($qualification['completed_year'] ?? ''),
                ]))) ?></li>
            <?php endforeach; ?>
        </ul>
        <h3 class="h6">Work and teaching experience</h3>
        <?php if ($submittedProfile['experience'] === []): ?>
            <p>No work experience recorded.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($submittedProfile['experience'] as $experience): ?>
                    <li class="text-break"><?= $escape(implode(' · ', array_filter([
                        $experience['position_title'], $experience['organisation'],
                        implode('–', array_filter([(string) ($experience['started_year'] ?? ''), (string) ($experience['ended_year'] ?? '')])),
                    ]))) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>
