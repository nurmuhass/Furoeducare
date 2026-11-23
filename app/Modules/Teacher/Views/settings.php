<div class="teacher-settings-grid">
    <section class="teacher-card" aria-labelledby="account-settings-title">
        <h2 id="account-settings-title">Account</h2>
        <p><a href="/settings/account">Complete account settings: password, verified email, phone and appearance</a></p>
        <p><a href="/privacy/controls">Disclosure preferences and data rights</a></p>
        <p><a href="/settings/notifications">Notification settings</a></p>
        <dl class="teacher-account-data">
            <div><dt>Account category</dt><dd><?= $escape($category) ?></dd></div>
            <div><dt>Email address</dt><dd><?= $escape($data['email']) ?> <span class="teacher-tag teacher-tag-success">Verified email</span></dd></div>
        </dl>
        <a href="/forgot-password">Reset password</a>
    </section>

    <section class="teacher-card" aria-labelledby="appearance-title">
        <h2 id="appearance-title">Appearance</h2>
        <?php if ($data['dashboardWorkspace'] ?? false): ?>
            <p>Choose light, dark or system default using Appearance in the dashboard sidebar. Your selection is saved in this browser.</p>
        <?php else: ?>
        <div class="teacher-appearance" data-theme-picker hidden>
            <label class="form-label" for="theme">Colour mode</label>
            <select id="theme" class="form-select theme-select" aria-describedby="theme-hint">
                <option value="system">System default</option>
                <option value="light">Light</option>
                <option value="dark">Dark</option>
            </select>
            <p id="theme-hint" class="teacher-muted">Saved in this browser.</p>
        </div>
        <?php endif; ?>
        <noscript><p class="teacher-muted">Enable JavaScript to change the colour mode here.</p></noscript>
    </section>
</div>
