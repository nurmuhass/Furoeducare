<?php

require __DIR__ . '/fields.php';

$mode = $data['mode'];
$values = $data['values'];
$errors = $data['errors'];
$verify = $mode === 'verify';
$confirm = $data['confirm'];
$categories = ['teacher' => 'Teacher', 'nysc' => 'NYSC Member'];
$action = match ($mode) {
    'signup' => '/register/teacher',
    'verify' => '/verify-teacher',
    'setup' => '/dashboard/teacher/setup',
};
?>
<section class="page-band">
    <div class="site-shell access-grid">
        <div class="form-panel access-form">
            <h1><?= $escape($data['title']) ?></h1>
            <?php if ($verify && $confirm): ?>
                <p>Confirm your <?= $escape($categories[$values['category']]) ?> registration for <strong><?= $escape($values['email']) ?></strong> to open your private dashboard.</p>
            <?php elseif ($verify && $data['token'] !== ''): ?>
                <p>Enter your details and choose a password to finish registration on this browser.</p>
            <?php elseif ($mode === 'signup'): ?>
                <p>Register to build a private professional profile. We’ll send a verification link to your email address.</p>
            <?php elseif ($mode === 'setup'): ?>
                <p>Add your contact details to start your private professional profile.</p>
            <?php endif; ?>
            <?php if ($errors !== [] || $data['message'] !== ''): ?>
                <p class="form-summary" role="alert"><?= $escape($data['message'] ?: 'Review the highlighted fields and try again.') ?></p>
            <?php endif; ?>

            <?php if (!$verify || $data['token'] !== ''): ?>
                <form class="auth-form" method="post" action="<?= $escape($action) ?>">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <?php if ($verify): ?>
                        <input type="hidden" name="token" value="<?= $escape($data['token']) ?>">
                    <?php endif; ?>
                    <?php if ($confirm): ?>
                        <input type="hidden" name="confirm_registration" value="1">
                    <?php else: ?>
                        <?php
                        $field('category', 'Account category', $values['category'] ?? '', 'select', $categories, $errors['category'] ?? '', required: true);
                        $contactFields($values, $errors);
                        if ($mode !== 'setup') {
                            $field('email', 'Email address', $values['email'] ?? '', 'email',
                                ['autocomplete' => 'email', 'maxlength' => 254, 'required' => ''], $errors['email'] ?? '');
                        }
                        if ($mode !== 'setup') {
                            $passwordOptions = ['autocomplete' => 'new-password', 'minlength' => 12, 'maxlength' => 72, 'required' => ''];
                            $field('password', 'Password', type: 'password', options: $passwordOptions,
                                error: $errors['password'] ?? '', hint: 'Use at least 12 characters and at most 72 bytes.');
                            $field('password_confirmation', 'Confirm password', type: 'password', options: $passwordOptions,
                                error: $errors['password_confirmation'] ?? '');
                        }
                        ?>
                        <p class="form-next mb-1">The Terms of Use and Privacy Notice are drafts awaiting legal review.</p>
                        <?php foreach (['terms' => ['I accept the', '/terms', 'Terms of Use'], 'privacy' => ['I acknowledge the', '/privacy-policy', 'Privacy Notice']] as $key => [$prefix, $href, $label]): ?>
                            <div class="form-check">
                                <input class="form-check-input" id="teacher-<?= $key ?>" name="<?= $key ?>" type="checkbox" value="1" required
                                    <?= ($values[$key] ?? '') === '1' ? 'checked' : '' ?>
                                    <?= isset($errors[$key]) ? 'aria-invalid="true" aria-describedby="teacher-' . $key . '-error"' : '' ?>>
                                <label class="form-check-label" for="teacher-<?= $key ?>"><?= $escape($prefix) ?> <a href="<?= $href ?>"><?= $escape($label) ?></a></label>
                                <?php if (isset($errors[$key])): ?><p class="field-error" id="teacher-<?= $key ?>-error"><?= $escape($errors[$key]) ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <button class="btn button button-primary" type="submit"><?= $escape(match ($mode) {
                        'signup' => 'Send verification email',
                        'verify' => 'Verify email and continue',
                        'setup' => 'Create my profile',
                    }) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($verify): ?>
                <p class="form-next"><a href="/resend-verification?flow=teacher">Request a new verification link</a></p>
            <?php elseif ($mode === 'signup'): ?>
                <p class="form-next"><a href="/login">Log in</a> · <a href="/register">School and parent registration</a></p>
            <?php endif; ?>
        </div>
        <aside class="access-note">
            <h2>Your professional profile</h2>
            <p>After verifying your email, add your qualifications, experience and preferences at your own pace.</p>
            <p>Registration does not publish your profile. Your contact information and uploaded documents are private.</p>
        </aside>
    </div>
</section>
