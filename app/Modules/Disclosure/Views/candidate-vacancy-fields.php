<div>
    <label class="form-label" for="candidate-vacancy">Vacancy</label>
    <select class="form-select" name="vacancy_id" id="candidate-vacancy" required>
        <option value="">Choose a vacancy</option>
        <?php foreach ($data['vacancies'] as $vacancy): ?>
            <option value="<?= (int) $vacancy['id'] ?>"><?= $escape($vacancy['title']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div>
    <label class="form-label" for="candidate-purpose">Purpose</label>
    <input class="form-control" id="candidate-purpose" name="purpose" maxlength="250" value="<?= $escape($vacancyPurpose) ?>" required>
</div>
<div>
    <label class="form-label" for="candidate-requirements">Requirements</label>
    <p class="form-text" id="candidate-requirements-hint">Describe subjects, teaching levels or scheduling needs. Leave out personal and contact details.</p>
    <textarea class="form-control" id="candidate-requirements" name="requirements" maxlength="2000" rows="5"
              aria-describedby="candidate-requirements-hint" required><?= $escape($vacancyRequirements) ?></textarea>
</div>
