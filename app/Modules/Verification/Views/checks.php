<fieldset>
    <legend class="fs-6 mb-2">Verification checks</legend>
    <?php foreach ($checkLabels as $key => $label): ?>
        <label class="form-check d-flex align-items-baseline gap-2">
            <input class="form-check-input" type="checkbox" name="checks[]" value="<?= $escape($key) ?>" <?= ($checked[$key] ?? false) ? 'checked' : '' ?>>
            <span><?= $escape($label) ?></span>
        </label>
    <?php endforeach; ?>
</fieldset>
