<p class="form-next">The Terms of Use and Privacy Notice are drafts awaiting legal review.</p>
<?php foreach (['terms' => ['I accept the', '/terms', 'Terms of Use'], 'privacy' => ['I acknowledge the', '/privacy-policy', 'Privacy Notice']] as $key => [$intro, $link, $label]): ?>
    <div class="form-check">
        <input id="<?= $escape($fieldPrefix) ?>-<?= $key ?>" name="<?= $key ?>" value="1" type="checkbox" class="form-check-input" required
            <?= ($values[$key] ?? '') === '1' ? 'checked' : '' ?>
            <?= isset($errors[$key]) ? 'aria-invalid="true" aria-describedby="' . $escape($fieldPrefix) . '-' . $key . '-error"' : '' ?>>
        <label class="form-check-label" for="<?= $escape($fieldPrefix) ?>-<?= $key ?>"><?= $escape($intro) ?> <a href="<?= $link ?>"><?= $escape($label) ?></a></label>
        <?php if (isset($errors[$key])): ?><p class="field-error" id="<?= $escape($fieldPrefix) ?>-<?= $key ?>-error"><?= $escape($errors[$key]) ?></p><?php endif; ?>
    </div>
<?php endforeach; ?>
