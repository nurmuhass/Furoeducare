<?php

use Furoeducare\Modules\Auth\Role;

$path = $data['path'];
$values = $data['values'];
$errors = $data['errors'];
$email = ['email', 'Email address', 'email', 'email', 254, true];
$password = ['password', 'Password', 'password', 'new-password', 72, true];
$confirmation = ['password_confirmation', 'Confirm password', 'password', 'new-password', 72, true];

[$intro, $button, $fields] = match ($path) {
    '/login' => ['Sign in to your Furoeducare dashboard.', 'Log in', [
        $email, ['password', 'Password', 'password', 'current-password', 72, true],
    ]],
    '/register' => ['Schools, institutions, parents and guardians can start with their email address.', 'Send registration link', [$email]],
    '/resend-verification' => ['Enter your account email to request a new verification link.', 'Send verification email', [$email]],
    '/forgot-password' => ['Enter your account email to request a password reset link.', 'Send reset link', [$email]],
    '/verify-email' => ['Verify your email by choosing a password and, for a new account, your account category.', 'Verify my email', [
        ['role', 'Account category', 'role', '', 0, true],
        ['school_name', 'School name (for school accounts)', 'text', 'organization', 160, false],
        $password, $confirmation,
    ]],
    '/reset-password' => ['Choose a new password for your account.', 'Reset password', [$password, $confirmation]],
};
$requiresToken = in_array($path, ['/verify-email', '/reset-password'], true);
$showForm = !$requiresToken || $data['token'] !== '';
?>
<section class="page-band">
    <div class="site-shell access-grid">
        <div class="form-panel access-form">
            <h1><?= $escape($data['title']) ?></h1>
            <p class="form-description"><?= $escape($intro) ?></p>
            <?php if ($data['notice'] !== ''): ?>
                <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
            <?php endif; ?>
            <?php if ($data['message'] !== '' || $errors !== []): ?>
                <p class="form-summary" role="alert"><?= $escape($data['message'] ?: 'Review the highlighted fields and try again.') ?></p>
            <?php endif; ?>

            <?php if ($showForm): ?>
                <form class="auth-form" method="post" action="<?= $escape($path) ?>">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <?php if (($values['flow'] ?? '') === 'teacher'): ?>
                        <input type="hidden" name="flow" value="teacher">
                    <?php endif; ?>
                    <?php if ($requiresToken): ?>
                        <input type="hidden" name="token" value="<?= $escape($data['token']) ?>">
                    <?php endif; ?>
                    <?php foreach ($fields as [$name, $label, $type, $autocomplete, $max, $required]): ?>
                        <?php $id = 'auth-' . $name; $error = $errors[$name] ?? ''; ?>
                        <div class="form-field">
                            <label class="form-label" for="<?= $id ?>"><?= $escape($label) ?></label>
                            <?php if ($type === 'role'): ?>
                                <select id="<?= $id ?>" name="<?= $name ?>" class="form-select" required
                                    <?= $error !== '' ? 'aria-invalid="true" aria-describedby="' . $id . '-error"' : '' ?>>
                                    <option value="">Choose your category</option>
                                    <?php foreach (Role::publicRoles() as $role): ?>
                                        <option value="<?= $escape($role->value) ?>" <?= ($values[$name] ?? '') === $role->value ? 'selected' : '' ?>>
                                            <?= $escape($role->label()) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <?php $descriptions = array_filter([
                                    $error !== '' ? $id . '-error' : '',
                                    $name === 'password' && $path !== '/login' ? 'auth-password-hint' : '',
                                ]); ?>
                                <input id="<?= $id ?>" name="<?= $name ?>" type="<?= $escape($type) ?>" class="form-control"
                                       autocomplete="<?= $escape($autocomplete) ?>" maxlength="<?= $max ?>"
                                       <?= $required ? 'required' : '' ?>
                                       <?= $error !== '' ? 'aria-invalid="true"' : '' ?>
                                       <?= $descriptions !== [] ? 'aria-describedby="' . implode(' ', $descriptions) . '"' : '' ?>
                                       <?= $type === 'password' ? '' : 'value="' . $escape($values[$name] ?? '') . '"' ?>>
                                <?php if ($name === 'password' && $path !== '/login'): ?>
                                    <p class="form-next" id="auth-password-hint">Use at least 12 characters and at most 72 bytes.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($error !== ''): ?>
                                <p class="field-error" id="<?= $id ?>-error"><?= $escape($error) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button class="btn button button-primary" type="submit"><?= $escape($button) ?></button>
                </form>
            <?php elseif ($data['message'] === ''): ?>
                <p class="form-description">Open the link from your email or request a fresh one.</p>
            <?php endif; ?>

            <?php if ($path === '/login'): ?>
                <p class="form-next"><a href="/forgot-password">Forgot your password?</a> · <a href="/resend-verification">Resend verification email</a></p>
                <p class="form-next">New to Furoeducare? <a href="/register">Create an account</a></p>
            <?php elseif ($path === '/register'): ?>
                <p class="form-next"><a href="/register/teacher">Register as a teacher or NYSC member</a></p>
                <p class="form-next">Already have an account? <a href="/login">Log in</a></p>
                <p class="form-next"><a href="/privacy-policy">Privacy policy draft</a> and <a href="/terms">Terms draft</a></p>
            <?php else: ?>
                <?php if ($requiresToken): ?>
                    <p class="form-next"><a href="<?= $path === '/verify-email' ? '/resend-verification' : '/forgot-password' ?>">Request a new link</a></p>
                <?php endif; ?>
                <p class="form-next"><a href="/login">Return to log in</a></p>
            <?php endif; ?>
        </div>
        <aside class="access-note">
            <h2>Accounts for education in Nigeria</h2>
            <ul>
                <li><strong>Teachers and NYSC members</strong><span>Use your teacher account to start onboarding.</span></li>
                <li><strong>Schools and institutions</strong><span>School accounts start with limited access while awaiting management approval.</span></li>
                <li><strong>Parents and guardians</strong><span>Use your parent account to start onboarding.</span></li>
            </ul>
        </aside>
    </div>
</section>
