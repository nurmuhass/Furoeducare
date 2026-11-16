<footer class="site-footer">
    <div class="site-shell">
        <div class="footer-main">
            <div class="footer-brand">
                <a class="brand" href="/">
                    <span class="brand-mark" aria-hidden="true"></span>
                    <span><?= $escape($data['appName']) ?></span>
                </a>
                <p>Education recruitment and learning services for teachers, schools and families across Nigeria.</p>
            </div>
            <nav class="footer-links" aria-label="Footer">
                <div>
                    <p class="footer-label">Explore</p>
                    <a href="/find-teachers">Find teachers</a>
                    <a href="/teaching-jobs">Teaching jobs</a>
                    <a href="/digital-library">Digital library</a>
                    <a href="/training-workshops">Training and workshops</a>
                </div>
                <div>
                    <p class="footer-label">Furoeducare</p>
                    <a href="/about">About us</a>
                    <a href="/services">Our services</a>
                    <a href="/contact">Contact us</a>
                    <a href="/login">Log in</a>
                </div>
            </nav>
            <div class="footer-theme" data-theme-picker hidden>
                <label class="footer-label" for="theme">Appearance</label>
                <select id="theme" class="form-select theme-select">
                    <option value="system">System default</option>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                </select>
            </div>
        </div>
        <div class="footer-end">
            <span>© <?= gmdate('Y') ?> <?= $escape($data['appName']) ?></span>
            <div class="legal-links">
                <a href="/privacy-policy">Privacy policy</a>
                <a href="/terms">Terms of use</a>
            </div>
        </div>
    </div>
</footer>
