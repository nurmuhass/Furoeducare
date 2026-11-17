<div class="form-panel mb-4">
    <h3>Request access to candidate materials</h3>
    <p>Furoeducare reviews access for this recipient account and the purpose shown above. Any approval is limited to the selected candidate, scope and expiry.</p>
    <form class="auth-form" method="post" action="<?= $escape($root) ?>">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <input type="hidden" name="id" value="<?= $caseId ?>">
        <input type="hidden" name="action" value="request">
        <input type="hidden" name="notice_version" value="<?= $escape($policy->active() ? $policy->version : '') ?>">
        <div>
            <label class="form-label" for="candidate-disclosure-reference">Candidate</label>
            <select class="form-select" id="candidate-disclosure-reference" name="ref" required>
                <option value="">Choose a candidate</option>
                <?php foreach ($accessOptions as $candidate): ?>
                    <option value="<?= $escape($candidate['reference']) ?>">
                        <?= $escape($candidate['title'] === null ? $candidate['reference'] : $candidate['title'] . ' (' . $candidate['reference'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="candidate-disclosure-scope">Material requested</label>
            <select class="form-select" id="candidate-disclosure-scope" name="scope" required>
                <option value="">Choose a scope</option>
                <option value="professional">Professional profile</option>
                <option value="original_cv">Original CV</option>
            </select>
            <p class="form-text">The professional profile contains selected qualifications and experience. Request the original CV only if the professional profile is insufficient for this purpose.</p>
        </div>
        <label class="form-check">
            <input class="form-check-input" type="checkbox" name="ack" value="1" required>
            <span class="form-check-label"><?php if ($policy->noticeUrl !== ''): ?>I acknowledge the disclosure privacy notice. <?php endif; ?>I will use approved material only for this request, keep it within the named recipient account, and stop using it and delete copies when access expires or is revoked.</span>
        </label>
        <button class="btn button button-primary" type="submit">Request access</button>
    </form>
</div>
