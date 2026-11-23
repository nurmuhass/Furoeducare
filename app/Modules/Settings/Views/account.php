<?php
use Furoeducare\Modules\Auth\Role;
use Furoeducare\UI\Components;

$errors = $data['errors'] ?? [];
$values = $data['values'] ?? [];
$roles = $data['viewer']['roles'];
$activeAction = '';
$startForm = static function (string $action, string $confirmation = '') use ($escape, $data, &$activeAction): void {
    $activeAction = $action;
    ?><form method="post" action="/settings/account" class="auth-form"<?= $action === 'appearance' ? ' data-theme-picker' : '' ?><?= $confirmation !== '' ? ' data-confirm="' . $escape($confirmation) . '"' : '' ?>>
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <input type="hidden" name="action" value="<?= $escape($action) ?>"><?php
};
$fieldsFor = static function (string $prefix) use ($partials, $escape): Closure {
    $fieldPrefix = 'account-' . $prefix;
    require $partials . '/form-fields.php';
    return $field;
};
$passwordFor = static function (Closure $field) use ($errors, $values, &$activeAction): void {
    $error = ($values['_action'] ?? $activeAction) === $activeAction ? ($errors['current_password'] ?? '') : '';
    $field('current_password', 'Current password', '', 'password', ['autocomplete' => 'current-password', 'maxlength' => 72, 'required' => 'required'], $error);
};
$localPath = static fn (string $path): bool => str_starts_with($path, '/') && !str_starts_with($path, '//') && preg_match('/[\\\\\x00-\x20]/', $path) !== 1;
?>
<section class="page-band">
    <div class="site-shell">
        <a href="/dashboard">Back to dashboard</a>
        <h1>Account settings</h1>
        <p>Manage the sign-in and preferences for your one account across all your roles.</p>
        <?php if (($data['notice'] ?? '') !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
        <?= Components::error($errors) ?>
        <nav class="button-row mb-4" aria-label="Related settings">
            <a href="/settings/notifications">Notification preferences</a>
            <a href="/privacy/controls">Privacy controls</a>
            <a href="/privacy-policy">Privacy policy</a>
            <a href="/terms">Terms of use</a>
        </nav>
        <p class="small">Verification emails are required for email changes. Other email and dashboard updates follow your notification preferences.</p>

        <section class="form-panel mb-4" aria-labelledby="account-profile-heading">
            <h2 id="account-profile-heading">Profile details</h2>
            <p>Use your role profile to update the details specific to that role.</p>
            <?php if ($data['profileLinks'] === []): ?><p>No role profile editor is available for this account.</p><?php else: ?>
                <ul><?php foreach ($data['profileLinks'] as $link): ?><?php if ($localPath($link['path'])): ?><li><a href="<?= $escape($link['path']) ?>"><?= $escape($link['label']) ?></a></li><?php endif; ?><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if (in_array(Role::School, $roles, true)): ?>
                <p><a href="/dashboard/school?tab=profile">Update school organisation details</a>. Identity changes may need renewed review. Your school role stays on this one account.</p>
            <?php endif; ?>
            <?php if (in_array(Role::Admin, $roles, true)): ?>
                <?php $startForm('admin_profile', 'Save the staff display name for this account?'); $field = $fieldsFor('admin-profile'); ?>
                    <?php $field('display_name', 'Staff display name', $values['display_name'] ?? '', 'text', ['autocomplete' => 'name', 'maxlength' => 180, 'required' => 'required'], $errors['display_name'] ?? ''); $passwordFor($field); ?>
                    <button class="btn button button-primary" type="submit">Save staff name</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="form-panel mb-4" aria-labelledby="account-security-heading">
            <h2 id="account-security-heading">Password</h2>
            <?php $startForm('password', 'Change the password used to sign in to this account?'); $field = $fieldsFor('password'); $passwordFor($field); ?>
                <?php $field('password', 'New password', '', 'password', ['autocomplete' => 'new-password', 'minlength' => 12, 'maxlength' => 72, 'required' => 'required'], $errors['password'] ?? '', 'Use at least 12 characters and at most 72 bytes.'); ?>
                <?php $field('password_confirmation', 'Confirm new password', '', 'password', ['autocomplete' => 'new-password', 'maxlength' => 72, 'required' => 'required'], $errors['password_confirmation'] ?? ''); ?>
                <button class="btn button button-primary" type="submit">Change password</button>
            </form>
        </section>

        <section class="form-panel mb-4" aria-labelledby="account-email-heading">
            <h2 id="account-email-heading">Sign-in email</h2>
            <p>Current email: <strong><?= $escape($data['viewer']['email']) ?></strong></p>
            <p>Your current address remains your sign-in email until the new address is verified.</p>
            <?php if ($data['pendingEmail'] !== null): $pending = $data['pendingEmail']; ?>
                <p role="status">Verification pending for <strong><?= $escape($pending['new_email']) ?></strong>. The link expires <?= $escape($pending['expires_at']) ?> UTC.</p>
                <?php $startForm('cancel_email', 'Cancel this pending email change?'); $field = $fieldsFor('cancel-email'); $passwordFor($field); ?>
                    <button class="btn button button-outline" type="submit">Cancel email change</button>
                </form>
            <?php endif; ?>
            <?php $startForm('email', 'Send a verification link to the new sign-in email?'); $field = $fieldsFor('email'); ?>
                <?php $field('new_email', 'New email address', $values['new_email'] ?? '', 'email', ['autocomplete' => 'email', 'maxlength' => 254, 'required' => 'required'], $errors['new_email'] ?? ''); $passwordFor($field); ?>
                <button class="btn button button-primary" type="submit">Send verification link</button>
            </form>
        </section>

        <section class="form-panel mb-4" aria-labelledby="account-phone-heading">
            <h2 id="account-phone-heading">Contact phone numbers</h2>
            <?php if ($data['phoneProfiles'] === []): ?><p>No role contact phone is available to update.</p><?php endif; ?>
            <?php foreach ($data['phoneProfiles'] as $profile): $role = $profile['role']; $field = $fieldsFor('phone-' . $role); ?>
                <h3><?= $escape($profile['label']) ?></h3>
                <?php $startForm('phone', 'Update this role contact phone number?'); ?>
                    <input type="hidden" name="profile_role" value="<?= $escape($role) ?>">
                    <?php $field('phone', 'Phone number', ($values['profile_role'] ?? '') === $role ? ($values['phone'] ?? $profile['phone']) : $profile['phone'], 'tel', ['autocomplete' => 'tel', 'maxlength' => 30, 'required' => 'required'], ($values['profile_role'] ?? '') === $role ? ($errors['phone'] ?? '') : ''); $passwordFor($field); ?>
                    <button class="btn button button-primary" type="submit">Save <?= $escape($profile['label']) ?> phone</button>
                </form>
            <?php endforeach; ?>
        </section>

        <section class="form-panel mb-4" aria-labelledby="account-appearance-heading">
            <h2 id="account-appearance-heading">Appearance</h2>
            <?php $startForm('appearance'); ?>
                <div class="form-field">
                    <label class="form-label" for="account-appearance">Colour theme</label>
                    <select class="form-select" id="account-appearance" name="appearance"<?= isset($errors['appearance']) ? ' aria-invalid="true" aria-describedby="account-appearance-error"' : '' ?>>
                        <?php foreach (['system' => 'Follow device', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label): ?><option value="<?= $value ?>"<?= $data['appearance'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['appearance'])): ?><p class="field-error" id="account-appearance-error"><?= $escape($errors['appearance']) ?></p><?php endif; ?>
                </div>
                <button class="btn button button-primary" type="submit">Save appearance</button>
            </form>
        </section>

        <?php if ($data['teacher'] !== null): $teacher = $data['teacher']; ?>
            <section class="form-panel mb-4" aria-labelledby="account-publication-heading">
                <h2 id="account-publication-heading">Teacher visibility</h2>
                <p>Management controls publication of your teacher profile. Current publication status: <?= Components::status($teacher['public_status']) ?>.</p>
                <p>Managed disclosures are <?= $teacher['disclosures_paused'] ? 'paused' : 'not paused' ?>. <a href="/privacy/controls">Manage disclosure privacy controls</a>.</p>
                <p><a href="/dashboard/teacher?tab=availability">Manage teaching availability</a>. Availability and privacy choices do not publish your profile.</p>
            </section>
        <?php endif; ?>

        <section class="form-panel mb-4" aria-labelledby="account-deactivation-heading">
            <h2 id="account-deactivation-heading">Request account deactivation</h2>
            <p>This submits a request for review; it does not instantly delete your account or records. Use <a href="/privacy/controls">privacy controls</a> for data access or erasure requests.</p>
            <?php if ($data['deactivation'] !== null): $request = $data['deactivation']; ?>
                <p>Request status: <?= Components::status($request['status']) ?> · Submitted <?= $escape($request['created_at']) ?> UTC.</p>
                <?php if ($request['reason'] !== ''): ?><p><?= $escape($request['reason']) ?></p><?php endif; ?>
                <?php if ($request['status'] === 'pending'): ?>
                    <?php $startForm('cancel_deactivation', 'Cancel your pending account deactivation request?'); $field = $fieldsFor('cancel-deactivation'); $passwordFor($field); ?>
                        <button class="btn button button-outline" type="submit">Cancel deactivation request</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($data['deactivation'] === null || $data['deactivation']['status'] !== 'pending'): ?>
                <?php $startForm('deactivate', 'Submit an account deactivation request for review?'); $field = $fieldsFor('deactivate'); ?>
                    <?php $field('deactivation_reason', 'Reason for your request', $values['deactivation_reason'] ?? '', 'textarea', ['maxlength' => 1000, 'rows' => 4], $errors['deactivation_reason'] ?? '', 'Up to 1,000 characters. Leave out passwords and sensitive identity details.'); $passwordFor($field); ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="account-deactivation-confirmed" name="confirmed" value="1" required<?= isset($errors['confirmed']) ? ' aria-invalid="true" aria-describedby="account-deactivation-confirmed-error"' : '' ?>>
                        <label class="form-check-label" for="account-deactivation-confirmed">I understand this is a request for review and want to proceed.</label>
                        <?php if (isset($errors['confirmed'])): ?><p class="field-error" id="account-deactivation-confirmed-error"><?= $escape($errors['confirmed']) ?></p><?php endif; ?>
                    </div>
                    <button class="btn button button-outline" type="submit">Request deactivation</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</section>
