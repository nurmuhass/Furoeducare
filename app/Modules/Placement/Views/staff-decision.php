<section class="form-panel h-100" aria-labelledby="placement-<?= $escape($action) ?>-heading">
    <h2 id="placement-<?= $escape($action) ?>-heading" class="h4"><?= $escape($actionLabel) ?></h2>
    <?php if ($action === 'confirm'): ?>
        <p>Confirm that both parties agreed to the recorded terms and that the candidate has passed the required checks.</p>
    <?php elseif ($action === 'available'): ?>
        <p>Record the availability the candidate confirmed for this placement.</p>
    <?php elseif ($action === 'select'): ?>
        <p>Record the <?= $tutoring ? 'parent’s' : 'school’s' ?> decision when they have confirmed their choice directly with staff.</p>
    <?php endif; ?>
    <form class="auth-form" method="post" action="<?= $escape($root) ?>">
        <?php require __DIR__ . '/placement-form-fields.php'; ?>
        <?php if ($action === 'schedule'): ?>
            <div>
                <label class="form-label" for="placement-scheduled-at">Date and time in West Africa Time</label>
                <input class="form-control" type="datetime-local" id="placement-scheduled-at" name="scheduled_at"
                       value="<?= $placement['scheduled_at'] === null ? '' : $escape($time($placement['scheduled_at'])->format('Y-m-d\TH:i')) ?>" required>
            </div>
        <?php elseif ($action === 'offer'): ?>
            <div>
                <label class="form-label" for="placement-offer-summary"><?= $tutoring ? 'Arrangement details' : 'Offer details' ?></label>
                <textarea class="form-control" id="placement-offer-summary" name="offer_summary" rows="4" maxlength="1000"
                          aria-describedby="placement-offer-help" required></textarea>
                <p class="form-text" id="placement-offer-help">The candidate and requesting <?= $tutoring ? 'parent' : 'school' ?> see this summary. Use only the agreed terms and scheduling details.</p>
            </div>
            <div>
                <label class="form-label" for="placement-starts-on">Start date</label>
                <input class="form-control" type="date" id="placement-starts-on" name="starts_on" required>
            </div>
        <?php elseif ($action === 'safeguard'): ?>
            <fieldset>
                <legend class="h6">Safeguarding checklist</legend>
                <?php
                $checks = [
                    'identity' => 'I checked the tutor’s identity against the current reviewed evidence.',
                    'references' => 'I verified appropriate references and documented the outcome.',
                    'home_plan' => 'I reviewed the home visit arrangements and adult supervision expectations.',
                    'emergency_plan' => 'I confirmed the escalation and emergency contact arrangements with the responsible adults.',
                ];
                ?>
                <?php foreach ($checks as $field => $label): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="placement-check-<?= $escape($field) ?>" name="<?= $escape($field) ?>" value="1" required>
                        <label class="form-check-label" for="placement-check-<?= $escape($field) ?>"><?= $escape($label) ?></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <div>
                <label class="form-label" for="placement-safeguarding-reference">Safeguarding evidence reference</label>
                <input class="form-control" id="placement-safeguarding-reference" name="safeguarding_reference" maxlength="120"
                       aria-describedby="placement-safeguarding-help" required>
                <p class="form-text" id="placement-safeguarding-help">Enter the internal reference for the reviewing staff’s evidence record.</p>
            </div>
        <?php endif; ?>
        <div>
            <label class="form-label" for="placement-<?= $escape($action) ?>-note">Private staff decision note</label>
            <textarea class="form-control" id="placement-<?= $escape($action) ?>-note" name="note" rows="3" maxlength="1000" required></textarea>
        </div>
        <button class="btn button <?= in_array($action, ['reject', 'close'], true) ? 'button-outline' : 'button-primary' ?>"
                name="action" value="<?= $escape($action) ?>" type="submit"><?= $escape($actionLabel) ?></button>
    </form>
</section>
