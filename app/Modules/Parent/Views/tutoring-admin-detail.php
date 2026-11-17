<?php

use Furoeducare\Modules\Parent\TutoringStatus;
use Furoeducare\Support\NigerianStates;

$id = (int) $record['id'];
$action = $root . '?id=' . $id;
$version = $record['updated_at'];
$status = $data['status'];
$stages = $status->staffTransitions();
$candidates = $data['candidates'];
$references = array_column($candidates, 'teacher_reference');
$finder = $data['finder'];
?>
<p class="lead text-break mb-2"><?= $escape($record['subject']) ?></p>
<p><span class="status-badge"><?= $escape($status->label()) ?></span></p>
<?php if ($data['error'] !== ''): ?>
    <p class="form-summary" role="alert"><?= $escape($data['error']) ?></p>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <section class="form-panel mb-4" aria-labelledby="tutoring-details">
            <h2 class="h4" id="tutoring-details">Request details</h2>
            <?php require __DIR__ . '/tutoring-details.php'; ?>
        </section>
        <section class="form-panel mb-4" aria-labelledby="tutoring-contact">
            <h2 class="h4" id="tutoring-contact">Parent or guardian contact</h2>
            <p class="mb-1 text-break"><?= $escape($record['contact_first_name'] . ' ' . $record['contact_last_name']) ?></p>
            <p class="mb-0 text-break"><?= $escape($record['contact_email']) ?> · <?= $escape($record['contact_phone']) ?></p>
        </section>

        <?php if ($stages !== []): ?>
            <section class="form-panel" aria-labelledby="tutoring-update">
                <h2 class="h4" id="tutoring-update">Update request stage</h2>
                <form class="auth-form" method="post" action="<?= $escape($action) ?>">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="version" value="<?= $escape($version) ?>">
                    <input type="hidden" name="action" value="transition">
                    <div>
                        <label class="form-label" for="tutoring-next-stage">Next stage</label>
                        <select class="form-select" name="status" id="tutoring-next-stage" required>
                            <option value="">Choose a stage</option>
                            <?php foreach ($stages as $stage): ?>
                                <option value="<?= $escape($stage->value) ?>" <?= ($data['attempt']['stage'] ?? null) === $stage ? 'selected' : '' ?>>
                                    <?= $escape($stage->label()) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($status === TutoringStatus::ArrangementPending): ?>
                        <div>
                            <label class="form-label" for="tutoring-confirmed-teacher">Teacher for confirmation</label>
                            <select class="form-select" name="ref" id="tutoring-confirmed-teacher">
                                <option value="">Choose a teacher from the shortlist</option>
                                <?php foreach ($candidates as $candidate): ?>
                                    <?php $reference = $candidate['teacher_reference']; ?>
                                    <option value="<?= $escape($reference) ?>" <?= ($data['attempt']['reference'] ?? null) === $reference ? 'selected' : '' ?>>
                                        <?= $escape($candidate['title'] . ' · ' . $reference) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div>
                        <label class="form-label" for="tutoring-note">Note to the parent (required to close)</label>
                        <textarea class="form-control" name="note" id="tutoring-note" rows="3" maxlength="1000"><?= $escape($data['attempt']['note'] ?? '') ?></textarea>
                    </div>
                    <div><button class="btn button button-primary" type="submit">Update stage</button></div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <section class="detail-card mb-4" aria-labelledby="tutoring-shortlist">
            <h2 class="h4" id="tutoring-shortlist">Teacher shortlist</h2>
            <?php if ($candidates === []): ?>
                <p class="mb-0">The shortlist is empty.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($candidates as $candidate): ?>
                        <?php $reference = $candidate['teacher_reference']; ?>
                        <li class="list-group-item text-break">
                            <strong><?= $escape($candidate['title']) ?></strong>
                            <p class="mb-1"><?= $escape($reference) ?></p>
                            <?php if ($finder !== null): ?>
                                <form method="post" action="<?= $escape($action) ?>">
                                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="version" value="<?= $escape($version) ?>">
                                    <input type="hidden" name="ref" value="<?= $escape($reference) ?>">
                                    <button class="btn button button-outline" type="submit" name="action" value="remove_candidate"
                                        aria-label="Remove <?= $escape($reference) ?> from shortlist">Remove from shortlist</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="detail-card" aria-labelledby="tutoring-history">
            <h2 class="h4" id="tutoring-history">Request timeline</h2>
            <?php if ($data['history'] === []): ?>
                <p class="mb-0">No stage changes recorded.</p>
            <?php else: ?>
                <ol class="list-group list-group-flush">
                    <?php foreach ($data['history'] as $entry): ?>
                        <?php
                        $from = TutoringStatus::tryFrom($entry['from_status'] ?? '');
                        $to = TutoringStatus::from($entry['to_status']);
                        ?>
                        <li class="list-group-item">
                            <strong><?= $escape($from === null ? $to->label() : $from->label() . ' → ' . $to->label()) ?></strong>
                            <small class="d-block"><?= $escape(substr($entry['created_at'], 0, 16)) ?> UTC</small>
                            <?php if (($entry['note'] ?? '') !== ''): ?>
                                <p class="mb-0 text-break"><?= nl2br($escape($entry['note'])) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if ($finder !== null): ?>
    <?php
    $filters = $finder['filters'];
    $preferred = $finder['preferred'];
    $preferredReference = $preferred['reference'] ?? null;
    $listings = $finder['listings'];
    if ($preferred !== null) {
        $listings = [$preferred, ...array_filter($listings, static fn (array $listing): bool => $listing['reference'] !== $preferredReference)];
    }
    ?>
    <section class="mt-4" aria-labelledby="tutoring-teacher-finder">
        <h2 class="h4" id="tutoring-teacher-finder">Find teachers</h2>
        <form class="form-panel mb-3" method="get" action="<?= $escape($root) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="search" value="1">
            <div class="row g-3 align-items-end">
                <div class="col-md">
                    <label class="form-label" for="tutoring-search-subject">Subject</label>
                    <input class="form-control" id="tutoring-search-subject" name="subject" maxlength="100"
                           value="<?= $escape($filters['subject'] ?? '') ?>">
                </div>
                <div class="col-md">
                    <label class="form-label" for="tutoring-search-level">Teaching level</label>
                    <input class="form-control" id="tutoring-search-level" name="level" maxlength="100"
                           value="<?= $escape($filters['level'] ?? '') ?>">
                </div>
                <div class="col-md">
                    <label class="form-label" for="tutoring-search-state">State or FCT</label>
                    <select class="form-select" id="tutoring-search-state" name="state">
                        <option value="">Any state</option>
                        <?php foreach (NigerianStates::NAMES as $code => $label): ?>
                            <option value="<?= $escape($code) ?>" <?= ($filters['state'] ?? '') === $code ? 'selected' : '' ?>>
                                <?= $escape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto"><button class="btn button button-outline" type="submit">Search</button></div>
            </div>
        </form>

        <?php if ($listings === []): ?>
            <p class="form-panel">No available teachers match these filters.</p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($listings as $listing): ?>
                    <?php $reference = $listing['reference']; ?>
                    <div class="col-md-6 col-xl-4">
                        <article class="detail-card h-100 d-flex flex-column">
                            <?php if ($reference === $preferredReference): ?>
                                <p class="mb-1"><strong>Parent’s preferred teacher</strong></p>
                            <?php endif; ?>
                            <h3 class="h5 text-break"><?= $escape($listing['title']) ?></h3>
                            <p><?= $escape($reference) ?></p>
                            <?php require dirname(__DIR__, 2) . '/Directory/Views/facts.php'; ?>
                            <div class="button-row mt-auto">
                                <a href="/find-teachers/profile?ref=<?= $escape($reference) ?>">View public profile</a>
                                <?php if (in_array($reference, $references, true)): ?>
                                    <span class="status-badge">On shortlist</span>
                                <?php else: ?>
                                    <form method="post" action="<?= $escape($action) ?>">
                                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="version" value="<?= $escape($version) ?>">
                                        <input type="hidden" name="ref" value="<?= $escape($reference) ?>">
                                        <button class="btn button button-outline" type="submit" name="action" value="add_candidate"
                                            aria-label="Add <?= $escape($reference) ?> to shortlist">Add to shortlist</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($finder['previous'] !== null || $finder['next'] !== null): ?>
            <?php $query = ['id' => $id] + ($finder['searchExplicit'] ? ['search' => 1] + $filters : []); ?>
            <nav class="button-row mt-3" aria-label="Teacher search pages">
                <?php if ($finder['previous'] !== null): ?>
                    <a href="<?= $escape($root . '?' . http_build_query($query + ['before' => $finder['previous']])) ?>">Previous teachers</a>
                <?php endif; ?>
                <?php if ($finder['next'] !== null): ?>
                    <a href="<?= $escape($root . '?' . http_build_query($query + ['after' => $finder['next']])) ?>">Next teachers</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>
