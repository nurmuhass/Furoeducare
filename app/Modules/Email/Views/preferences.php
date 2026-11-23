<section class="page-band">
    <div class="site-shell">
        <a href="/dashboard">Back to dashboard</a>
        <h1 class="my-3">Email notification settings</h1>
        <?php if ($data['notice'] !== ''): ?>
            <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
        <?php endif; ?>
        <?php if ($data['error'] !== ''): ?>
            <p class="alert alert-danger" role="alert"><?= $escape($data['error']) ?></p>
        <?php endif; ?>
        <div class="form-panel">
            <h2>Security emails are always on</h2>
            <p>Email verification and password reset emails are essential to protect and access your account. These cannot be turned off.</p>
            <form method="post" action="<?= $escape($data['path']) ?>">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <fieldset aria-describedby="notification-help">
                    <legend>Optional email notifications</legend>
                    <p id="notification-help">Choose which updates you receive by email. Your choices apply to optional emails waiting to be sent as well as future updates. Updates and messages remain available in your account.</p>
                    <?php foreach ($data['categories'] as $category => $label): ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="enabled[]" id="email-<?= $escape($category) ?>" value="<?= $escape($category) ?>"<?= $data['enabled'][$category] ? ' checked' : '' ?>>
                            <label class="form-check-label" for="email-<?= $escape($category) ?>"><?= $escape($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <button class="btn button button-primary mt-3" type="submit">Save email preferences</button>
            </form>
        </div>
    </div>
</section>
