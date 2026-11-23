<section class="page-band"><div class="site-shell">
    <h1>Verify your new email</h1>
    <?= \Furoeducare\UI\Components::error($data['errors']) ?>
    <?php if ($data['challenge'] !== null): ?>
        <div class="form-panel"><p>Confirm <strong><?= $escape($data['challenge']['new_email']) ?></strong> as your new sign-in address. Your existing account and roles will remain together.</p>
            <form class="auth-form" method="post" action="/settings/email-change" data-confirm="Confirm this verified sign-in email change?">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>"><input type="hidden" name="token" value="<?= $escape($data['token']) ?>">
                <?php $fieldPrefix = 'email-confirmation'; require $partials . '/form-fields.php'; $field('current_password', 'Current password', '', 'password', ['autocomplete' => 'current-password', 'required' => '', 'maxlength' => 72], $data['errors']['current_password'] ?? ''); ?>
                <button class="btn button button-primary" type="submit">Verify and change sign-in email</button>
            </form>
        </div>
    <?php endif; ?>
    <p class="mt-3"><a href="/settings/account">Return to account settings</a></p>
</div></section>
