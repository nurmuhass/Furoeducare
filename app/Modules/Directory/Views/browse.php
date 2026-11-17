<?php

use Furoeducare\Modules\Directory\DirectoryFilters;
use Furoeducare\Support\NigerianStates;

$filters = $data['filters'];
$savedOnly = $data['savedOnly'];
$path = $savedOnly ? '/find-teachers/saved' : '/find-teachers';
?>
<section class="page-band">
    <div class="site-shell page-intro">
        <h1><?= $escape($data['title']) ?></h1>
        <p class="page-lede"><?= $savedOnly
            ? 'Return to the available teachers you’ve saved.'
            : 'Find available teachers by subject, location and teaching preference.' ?></p>
        <?php if ($data['canSave']): ?>
            <nav class="button-row mt-3" aria-label="Teacher directory">
                <?php if ($savedOnly): ?>
                    <a href="/find-teachers">Find teachers</a>
                <?php else: ?>
                    <a href="/find-teachers/saved">Saved teachers</a>
                <?php endif; ?>
                <a href="/find-teachers/requests">My teacher requests</a>
            </nav>
        <?php endif; ?>
    </div>
</section>
<section class="site-shell section-space">
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice mb-3" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
    <?php if (!$savedOnly): ?>
        <form class="form-panel mb-4" method="get" action="<?= $escape($path) ?>" role="search" aria-label="Find teachers">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="directory-q">Search teachers</label>
                    <input class="form-control" id="directory-q" type="search" name="q" maxlength="100"
                           placeholder="Try mathematics or science" value="<?= $escape($filters['q'] ?? '') ?>">
                </div>
                <?php foreach (['subject' => 'Subject', 'level' => 'Teaching level', 'city' => 'City', 'qualification' => 'Qualification'] as $key => $label): ?>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <label class="form-label" for="directory-<?= $escape($key) ?>"><?= $escape($label) ?></label>
                        <input class="form-control" id="directory-<?= $escape($key) ?>" name="<?= $escape($key) ?>" maxlength="100"
                               value="<?= $escape($filters[$key] ?? '') ?>">
                    </div>
                <?php endforeach; ?>
                <?php foreach (['state' => ['State or FCT', NigerianStates::NAMES],
                    'availability' => ['Availability', DirectoryFilters::MODE_LABELS],
                    'tutoring' => ['Tutoring', ['home' => 'Home tutoring', 'online' => 'Online tutoring']]] as $key => [$label, $choices]): ?>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <label class="form-label" for="directory-<?= $escape($key) ?>"><?= $escape($label) ?></label>
                        <select class="form-select" id="directory-<?= $escape($key) ?>" name="<?= $escape($key) ?>">
                            <option value="">Any</option>
                            <?php foreach ($choices as $value => $choice): ?>
                                <option value="<?= $escape($value) ?>" <?= ($filters[$key] ?? '') === $value ? 'selected' : '' ?>><?= $escape($choice) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                <div class="col-12 col-sm-6 col-xl-3">
                    <label class="form-label" for="directory-experience">Minimum years of experience</label>
                    <input class="form-control" id="directory-experience" type="number" min="0" max="70" name="experience"
                           value="<?= $escape($filters['experience'] ?? '') ?>">
                </div>
                <div class="col-12 button-row">
                    <button class="btn button button-primary" type="submit">Search teachers</button>
                    <?php if ($filters !== []): ?><a href="/find-teachers">Clear filters</a><?php endif; ?>
                </div>
            </div>
        </form>
    <?php endif; ?>
    <?php if ($data['listings'] === []): ?>
        <div class="form-panel" data-empty-state>
            <h2><?= $savedOnly ? 'No available saved teachers' : ($filters === [] ? 'No teachers are listed on this page' : 'No teachers match your search') ?></h2>
            <?php if ($savedOnly || $filters !== []): ?>
                <p class="mb-0"><a href="/find-teachers">Browse all available teachers</a></p>
            <?php else: ?>
                <p class="mb-0">Check back as new profiles become available.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <h2 class="h4 mb-3"><?= $savedOnly ? 'Your available saved teachers' : 'Available teachers' ?></h2>
        <div class="row g-3">
            <?php foreach ($data['listings'] as $listing): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <article class="detail-card h-100 d-flex flex-column">
                        <div class="d-flex gap-3 align-items-start">
                            <?php if ($listing['has_photo']): ?>
                                <img class="directory-card-photo" src="/find-teachers/photo?ref=<?= $escape($listing['reference']) ?>"
                                     width="64" height="64" loading="lazy" alt="">
                            <?php endif; ?>
                            <div class="text-break">
                                <p class="mb-1">Ref <?= $escape($listing['reference']) ?></p>
                                <h3 class="h5 mb-1"><?= $escape($listing['professional_name'] ?? $listing['title']) ?></h3>
                                <?php if ($listing['professional_name'] !== null): ?><p><?= $escape($listing['title']) ?></p><?php endif; ?>
                            </div>
                        </div>
                        <p class="mt-3 text-break"><?= $escape($listing['bio']) ?></p>
                        <?php require __DIR__ . '/facts.php'; ?>
                        <div class="button-row mt-auto pt-3">
                            <a class="btn button button-outline" href="/find-teachers/profile?ref=<?= $escape($listing['reference']) ?>">View public profile</a>
                            <?php require __DIR__ . '/actions.php'; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($data['previous'] !== null || $data['next'] !== null): ?>
        <nav class="button-row mt-4" aria-label="Teacher results pages">
            <?php if ($data['previous'] !== null): ?>
                <a class="btn button button-outline" href="<?= $escape($path . '?' . http_build_query($filters + ['before' => $data['previous']])) ?>">Previous page</a>
            <?php endif; ?>
            <?php if ($data['next'] !== null): ?>
                <a class="btn button button-outline" href="<?= $escape($path . '?' . http_build_query($filters + ['after' => $data['next']])) ?>">Next page</a>
            <?php endif; ?>
        </nav>
    <?php elseif ($data['listings'] === [] && $data['hasCursor']): ?>
        <p class="mt-3"><a href="<?= $escape($path . ($filters === [] ? '' : '?' . http_build_query($filters))) ?>">Back to the first page</a></p>
    <?php endif; ?>
</section>
