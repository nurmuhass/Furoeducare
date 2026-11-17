<?php

use Furoeducare\Modules\Directory\AdminDirectoryController;
use Furoeducare\Modules\Directory\DirectoryController;
use Furoeducare\Modules\Directory\DirectoryFilters;
use Furoeducare\Support\NigerianStates;

$teacher = $data['teacher'];
$listing = $teacher['listing'];
$draft = $data['draft'];
$id = (int) $teacher['teacher_id'];
$revision = (int) ($listing['revision'] ?? 0);
$action = AdminDirectoryController::ROOT . '?id=' . $id;
$approved = ($listing['approved_at'] ?? null) !== null;
?>
<section class="page-band">
    <div class="site-shell">
        <nav class="d-flex flex-wrap gap-3 mb-3" aria-label="Directory administration">
            <a href="/dashboard/admin/verification/teachers?id=<?= $id ?>">Teacher verification review</a>
            <a href="<?= $escape(DirectoryController::ADMIN_REQUESTS) ?>">Directory requests</a>
        </nav>
        <h1 class="my-3">Manage public teacher listing</h1>
        <p class="lead"><?= $escape($teacher['first_name'] . ' ' . $teacher['last_name']) ?></p>
        <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
        <?php if ($data['error'] !== ''): ?><p class="form-summary" role="alert"><?= $escape($data['error']) ?></p><?php endif; ?>

        <div class="row g-4 align-items-start">
            <div class="col-lg-8">
                <section class="form-panel" aria-labelledby="listing-fields">
                    <h2 id="listing-fields">Public listing</h2>
                    <?php if ($data['selfReview']): ?>
                        <p>Another administrator must manage this listing.</p>
                    <?php else: ?>
                        <p>Write a short teaching summary for the public directory. Keep personal names, references, contact details, links, and street addresses out of these fields.</p>
                        <form class="auth-form" method="post" action="<?= $escape($action) ?>" enctype="multipart/form-data">
                            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="revision" value="<?= $revision ?>">
                            <input type="hidden" name="operation" value="publish">

                            <div>
                                <label class="form-label" for="listing-title">Professional title</label>
                                <input class="form-control" id="listing-title" name="title" value="<?= $escape($draft['title'] ?? '') ?>" maxlength="120" required>
                            </div>
                            <div>
                                <label class="form-label" for="listing-bio">Biography</label>
                                <textarea class="form-control" id="listing-bio" name="bio" rows="4" maxlength="600" required><?= $escape($draft['bio'] ?? '') ?></textarea>
                            </div>
                            <div>
                                <label class="form-label" for="listing-qualification">Qualification summary</label>
                                <input class="form-control" id="listing-qualification" name="qualification" value="<?= $escape($draft['qualification'] ?? '') ?>" maxlength="160" required>
                            </div>
                            <div>
                                <label class="form-label" for="listing-experience">Years of teaching experience</label>
                                <input class="form-control" id="listing-experience" name="experience" type="number" min="0" max="70" value="<?= $escape((string) ($draft['experience'] ?? '')) ?>" required>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="listing-state">State or FCT</label>
                                    <select class="form-select" id="listing-state" name="state_code" required>
                                        <option value="">Choose a state</option>
                                        <?php foreach (NigerianStates::NAMES as $code => $state): ?>
                                            <option value="<?= $escape($code) ?>" <?= ($draft['state_code'] ?? '') === $code ? 'selected' : '' ?>><?= $escape($state) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="listing-city">City or town (optional)</label>
                                    <input class="form-control" id="listing-city" name="city" value="<?= $escape($draft['city'] ?? '') ?>" maxlength="80">
                                </div>
                            </div>
                            <fieldset>
                                <legend class="h6">Available for</legend>
                                <?php foreach (DirectoryFilters::MODE_LABELS as $value => $label): ?>
                                    <label class="form-check d-flex align-items-baseline gap-2">
                                        <input class="form-check-input" type="checkbox" name="availability[]" value="<?= $escape($value) ?>" <?= in_array($value, $draft['availability'], true) ? 'checked' : '' ?>>
                                        <?= $escape($label) ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <div>
                                <label class="form-label" for="listing-subjects">Subjects</label>
                                <textarea class="form-control" id="listing-subjects" name="subjects" rows="2" maxlength="800" aria-describedby="listing-label-hint" required><?= $escape($draft['subjects']) ?></textarea>
                                <p class="form-text mb-0" id="listing-label-hint">Enter up to 12 subjects and 12 teaching levels. Separate each entry with a comma or new line.</p>
                            </div>
                            <div>
                                <label class="form-label" for="listing-levels">Teaching levels</label>
                                <textarea class="form-control" id="listing-levels" name="levels" rows="2" maxlength="800" aria-describedby="listing-label-hint" required><?= $escape($draft['levels']) ?></textarea>
                            </div>
                            <fieldset class="border-top pt-3">
                                <legend class="h6">Optional public identity</legend>
                                <label class="form-check d-flex align-items-baseline gap-2" for="listing-name-authorised">
                                    <input class="form-check-input" id="listing-name-authorised" type="checkbox" name="name_authorised" value="1" <?= ($draft['name_authorised'] ?? false) ? 'checked' : '' ?>>
                                    The teacher separately authorised this professional name for public display.
                                </label>
                                <label class="form-label mt-2" for="listing-professional-name">Professional name</label>
                                <input class="form-control" id="listing-professional-name" name="professional_name" value="<?= $escape($draft['professional_name'] ?? '') ?>" maxlength="100">
                                <label class="form-check d-flex align-items-baseline gap-2 mt-3" for="listing-photo-authorised">
                                    <input class="form-check-input" id="listing-photo-authorised" type="checkbox" name="photo_authorised" value="1" <?= ($draft['photo_authorised'] ?? false) ? 'checked' : '' ?>>
                                    The teacher separately authorised this photo for public display.
                                </label>
                                <label class="form-label mt-2" for="listing-photo">Upload a public photo</label>
                                <input class="form-control" id="listing-photo" type="file" name="photo" accept="image/jpeg,image/png" aria-describedby="listing-photo-hint">
                                <div class="form-text" id="listing-photo-hint">
                                    JPEG or PNG up to 2 MB. Review the image for private details before uploading.
                                    <?php if ($listing['has_photo'] ?? false): ?>
                                        A photo is on file. Confirm its authorisation and leave the file blank to keep it.
                                    <?php endif; ?>
                                    Saving with an optional authorisation unchecked removes that detail from the listing.
                                </div>
                            </fieldset>
                            <fieldset class="border-top pt-3">
                                <legend class="h6">Publication approval</legend>
                                <label class="form-check d-flex align-items-baseline gap-2" for="listing-authorised">
                                    <input class="form-check-input" id="listing-authorised" type="checkbox" name="authorised" value="1" <?= ($draft['authorised'] ?? false) ? 'checked' : '' ?> required>
                                    The teacher authorised publishing this listing as shown.
                                </label>
                                <label class="form-label mt-2" for="listing-approval">Private authorisation record</label>
                                <textarea class="form-control" id="listing-approval" name="approval_basis" rows="3" maxlength="1000" aria-describedby="listing-approval-hint" required><?= $escape($draft['approval_basis'] ?? '') ?></textarea>
                                <p class="form-text mb-0" id="listing-approval-hint">Record how and when permission was confirmed, including any optional details covered. Only administrators can see this record.</p>
                            </fieldset>
                            <button class="btn button button-primary" type="submit">Publish listing</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
            <div class="col-lg-4">
                <section class="form-panel" aria-labelledby="listing-status">
                    <h2 id="listing-status">Publication status</h2>
                    <?php if ($approved): ?>
                        <p>Approved <?= $escape(substr((string) $listing['approved_at'], 0, 10)) ?></p>
                        <p>Public visibility depends on the teacher’s account status and current availability.</p>
                        <?php if (!$data['selfReview']): ?>
                            <form method="post" action="<?= $escape($action) ?>">
                                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="revision" value="<?= $revision ?>">
                                <button class="btn button button-outline" type="submit" name="operation" value="withdraw">Withdraw listing</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>Awaiting publication approval.</p>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</section>
