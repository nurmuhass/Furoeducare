<?php

$fieldPrefix = 'school';
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
            <?php if (!$verify): ?><p class="form-description">Register one account for your school or educational institution.</p><?php endif; ?>
            <?php if ($data['message'] !== '' || $errors !== []): ?>
                <p class="form-summary" role="alert"><?= $escape($data['message'] ?: 'Review the highlighted fields and try again.') ?></p>
            <?php endif; ?>
            <?php if (!$verify || $data['token'] !== ''): ?>
                <form class="auth-form" method="post" action="<?= $verify ? '/verify-school' : '/register/school' ?>">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <?php if ($verify): ?><input type="hidden" name="token" value="<?= $escape($data['token']) ?>"><?php endif; ?>
                    <?php if ($reference !== null): ?><input type="hidden" name="ref" value="<?= $escape($reference) ?>"><?php endif; ?>
                    <?php if ($data['confirm']): ?>
                        <input type="hidden" name="confirm_registration" value="1">
                        <p>Confirm registration for <strong><?= $escape($values['school_name']) ?></strong> using <?= $escape($values['email']) ?>.</p>
                    <?php else: ?>
                        <?php if ($verify && $data['message'] === ''): ?><p>Enter your school details and choose a password to finish registration on this browser.</p><?php endif; ?>
                        <?php require __DIR__ . '/profile-fields.php'; ?>
                        <?php
                        $password = ['required' => '', 'maxlength' => 72, 'minlength' => 12, 'autocomplete' => 'new-password'];
                        $field('password', 'Password', type: 'password', options: $password,
                            error: $errors['password'] ?? '', hint: 'Use at least 12 characters and at most 72 bytes.');
                        $field('password_confirmation', 'Confirm password', type: 'password', options: $password, error: $errors['password_confirmation'] ?? '');
                        require __DIR__ . '/legal-fields.php';
                        ?>
                    <?php endif; ?>
                    <button class="btn button button-primary" type="submit"><?= $verify ? 'Verify email and continue' : 'Send verification email' ?></button>
                </form>
            <?php endif; ?>
            <?php if ($verify): ?>
                <p class="form-next"><a href="<?= $escape('/resend-verification?flow=school' . ($reference !== null ? '&ref=' . $reference : '')) ?>">Request a new school verification link</a></p>
            <?php else: ?>
                <p class="form-next">Already registered? <a href="/login">Log in</a></p>
            <?php endif; ?>
        </div>
        <aside class="access-note">
            <h2>School account review</h2>
            <p>After email verification, you can edit your school profile, browse public resources and prepare private recruitment drafts.</p>
            <p>Furoeducare must approve your school before recruitment requests can be processed.</p>
        </aside>
    </div>
</section>
