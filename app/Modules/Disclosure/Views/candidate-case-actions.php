<?php if (!$data['assignedToViewer'] && !$closed && $contextLinked): ?>
    <form method="post" action="<?= $escape($root) ?>">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <input type="hidden" name="id" value="<?= $caseId ?>">
        <button class="btn button button-primary" name="action" value="claim" type="submit">Assign to me</button>
    </form>
<?php endif; ?>

<?php if ($data['canPrepare']): ?>
    <form class="auth-form mb-3" method="post" action="<?= $escape($root) ?>">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <input type="hidden" name="id" value="<?= $caseId ?>">
        <div>
            <label class="form-label" for="candidate-add-reference">Candidate reference</label>
            <input class="form-control" id="candidate-add-reference" name="ref" maxlength="35"
                   aria-describedby="candidate-add-hint" <?= $data['options'] !== [] ? 'list="candidate-add-options"' : '' ?> required>
            <?php if ($data['options'] !== []): ?>
                <datalist id="candidate-add-options">
                    <?php foreach ($data['options'] as $option): ?>
                        <option value="<?= $escape($option['reference']) ?>"><?= $escape($option['title']) ?></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endif; ?>
            <p class="form-text" id="candidate-add-hint">Use a reference from this case or <a href="/find-teachers">Find Teachers</a>.</p>
        </div>
        <button class="btn button button-primary" name="action" value="add" type="submit">Add to shortlist</button>
    </form>
    <?php if ($data['shortlist'] !== []): ?>
        <form class="mb-3" method="post" action="<?= $escape($root) ?>">
            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
            <input type="hidden" name="id" value="<?= $caseId ?>">
            <button class="btn button button-primary" name="action" value="publish" type="submit">Publish shortlist</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
<?php if ($data['assignedToViewer'] && !$closed): ?>
    <form method="post" action="<?= $escape($root) ?>">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <input type="hidden" name="id" value="<?= $caseId ?>">
        <button class="btn button button-outline" name="action" value="close" type="submit">Close candidate request</button>
    </form>
<?php endif; ?>
