<?php use Furoeducare\Modules\Parent\TutoringInput; ?>
<?php if (!($data['parentTutoring'] ?? false) || ((int) $listing['current_modes'] & TutoringInput::modeMask('either')) !== 0): ?>
    <a class="btn button button-primary" href="/find-teachers/request?ref=<?= $escape($listing['reference']) ?>">Request this teacher</a>
<?php endif; ?>
<?php if ($data['canSave']): ?>
    <form method="post" action="/find-teachers/saved">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <input type="hidden" name="ref" value="<?= $escape($listing['reference']) ?>">
        <input type="hidden" name="action" value="<?= ($listing['saved'] ?? false) ? 'remove' : 'save' ?>">
        <?php if ($data['savedOnly'] ?? false): ?><input type="hidden" name="from" value="saved"><?php endif; ?>
        <button class="btn button button-outline" type="submit"><?= ($listing['saved'] ?? false) ? 'Remove saved teacher' : 'Save teacher' ?></button>
    </form>
<?php endif; ?>
