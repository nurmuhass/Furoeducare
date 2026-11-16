<p class="form-description" id="<?= $escape($form['id']) ?>-intro">Interface preview. Use sample details and leave out personal information.</p>
<noscript><p class="form-description">Enable JavaScript to try the preview.</p></noscript>
<form class="preview-form" method="post" autocomplete="off" novalidate data-preview-form
      data-ready="<?= $escape($form['ready']) ?>" aria-describedby="<?= $escape($form['id']) ?>-intro">
    <fieldset disabled>
        <legend class="sr-only">Preview fields</legend>
        <p class="form-summary" data-form-summary tabindex="-1" hidden></p>
        <?php foreach ($form['fields'] as $field): ?>
            <?php $id = $escape($field['id']); ?>
            <div class="form-field">
                <label class="form-label" for="<?= $id ?>"><?= $escape($field['label']) ?></label>
                <?php if (isset($field['options'])): ?>
                    <select id="<?= $id ?>" class="form-select" required aria-describedby="<?= $id ?>-error">
                        <option value="">Choose an option</option>
                        <?php foreach ($field['options'] as $option): ?>
                            <option><?= $escape($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($field['type'] === 'textarea'): ?>
                    <textarea id="<?= $id ?>" class="form-control" rows="4" maxlength="<?= $field['max'] ?>" required aria-describedby="<?= $id ?>-error"></textarea>
                <?php else: ?>
                    <input id="<?= $id ?>" class="form-control" type="<?= $escape($field['type']) ?>" maxlength="<?= $field['max'] ?>" required aria-describedby="<?= $id ?>-error">
                <?php endif; ?>
                <p id="<?= $id ?>-error" class="field-error" hidden></p>
            </div>
        <?php endforeach; ?>
        <button class="btn button button-primary" type="submit"><?= $escape($form['button']) ?></button>
    </fieldset>
    <p class="form-status" data-form-status role="status"></p>
</form>
