<?php

$fieldPrefix = 'parent';
require $partials . '/form-fields.php';
$values = $data['values'];
$errors = $data['errors'];
$verify = $data['mode'] === 'verify';
$registration = true;
$reference = $data['reference'];
?>
<section class="page-band">
    <div class="site-shell access-grid">
        <div class="form-panel access-form">
            <h1><?= $escape($data['title']) ?></h1>
            <?php if ($data['message'] !== '' || $errors !== []): ?>
                <p class="form-summary" role="alert"><?= $escape($data['message'] ?: 'Review the highlighted fields and try again.') ?></p>
            <?php endif; ?>
            <?php if (!$verify || $data['token'] !== ''): ?>
                <form class="auth-form" method="post" action="<?= $verify ? '/verify-parent' : '/register/parent' ?>">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <?php if ($verify): ?><input type="hidden" name="token" value="<?= $escape($data['token']) ?>"><?php endif; ?>
                    <?php if ($reference !== null): ?><input type="hidden" name="ref" value="<?= $escape($reference) ?>"><?php endif; ?>
                    <?php if ($data['confirm']): ?>
                        <input type="hidden" name="confirm_registration" value="1">
                        <p>Confirm registration for <strong><?= $escape($values['first_name'] . ' ' . $values['last_name']) ?></strong> using <?= $escape($values['email']) ?>.</p>
                    <?php else: ?>
                        <p><?= $verify ? 'Enter your details and choose a password to finish registration on this browser.' : 'Enter the parent or guardian’s contact details.' ?></p>
                        <?php require __DIR__ . '/profile-fields.php'; ?>
                        <?php
                        $password = ['required' => '', 'maxlength' => 72, 'minlength' => 12, 'autocomplete' => 'new-password'];
                        $field('password', 'Password', type: 'password', options: $password,
                            error: $errors['password'] ?? '', hint: 'Use at least 12 characters and at most 72 bytes.');
                        $field('password_confirmation', 'Confirm password', type: 'password', options: $password, error: $errors['password_confirmation'] ?? '');
                        require $partials . '/legal-acceptance.php';
                        ?>
                    <?php endif; ?>
                    <button class="btn button button-primary" type="submit"><?= $verify ? 'Verify email and continue' : 'Send verification email' ?></button>
                </form>
            <?php endif; ?>
            <p class="form-next">
                <?php if ($verify): ?><a href="<?= $escape('/resend-verification?flow=parent' . ($reference !== null ? '&ref=' . $reference : '')) ?>">Request a new parent verification link</a>
                <?php else: ?><a href="/login">Log in to an existing account</a><?php endif; ?>
            </p>
        </div>
        <aside class="access-note">
            <h2>Your parent dashboard</h2>
            <p>Browse public teacher profiles and keep your tutoring requests in one place.</p>
            <p>Verify your email to save and submit requests.</p>
        </aside>
    </div>
</section>
