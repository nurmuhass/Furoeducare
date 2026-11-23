<section class="page-band">
    <div class="site-shell">
        <h1>Notification settings</h1>
        <p class="page-lede">Choose where you receive each type of update.</p>
        <nav class="d-flex gap-3" aria-label="Notification navigation"><a href="/notifications">Notifications</a><a href="/dashboard">Dashboard</a></nav>
    </div>
</section>
<div class="site-shell section-space">
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
    <form class="form-panel" method="post" action="/settings/notifications">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <input type="hidden" name="channels" value="independent">
        <p>In-app and email choices are independent. Existing notifications remain in your inbox. Essential account verification and password reset emails are always sent.</p>
        <?php foreach ($data['categories'] as $key => $label): ?>
            <fieldset class="border rounded p-3 mb-3">
                <legend class="h5 float-none w-auto px-2"><?= $escape($label) ?></legend>
                <div class="d-flex gap-4 flex-wrap">
                    <?php foreach (['in_app' => 'In-app', 'email' => 'Email'] as $channel => $channelLabel): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="notification-<?= $escape($channel . '-' . $key) ?>" name="<?= $escape($channel) ?>[]" value="<?= $escape($key) ?>"<?= ($data['preferences'][$channel][$key] ?? true) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="notification-<?= $escape($channel . '-' . $key) ?>"><?= $escape($channelLabel) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>
        <button class="btn button button-primary" type="submit">Save notification settings</button>
    </form>
</div>
