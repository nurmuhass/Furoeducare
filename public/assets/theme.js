(() => {
    const storageKey = 'furoeducare-theme';
    const root = document.documentElement;
    const account = root.getAttribute('data-account-appearance');
    const authenticated = account !== null;
    const themes = ['system', 'light', 'dark'];
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    let selection = themes.includes(account) ? account : 'system';
    if (!authenticated) {
        try {
            const saved = window.localStorage.getItem(storageKey);
            if (themes.includes(saved)) selection = saved;
        } catch {
            // Storage may be disabled; the current page still works.
        }
    }
    const applyTheme = () => {
        root.setAttribute('data-bs-theme', selection === 'system' ? (systemTheme.matches ? 'dark' : 'light') : selection);
        root.setAttribute('data-theme-preference', selection);
    };
    applyTheme();
    systemTheme.addEventListener('change', applyTheme);
    document.addEventListener('DOMContentLoaded', () => {
        const pickers = Array.from(document.querySelectorAll('[data-theme-picker]'));
        pickers.forEach(picker => {
            const control = picker.querySelector('select[name="appearance"]');
            if (!control) return;
            control.value = selection;
            picker.removeAttribute('hidden');
            control.addEventListener('change', () => {
                if (!themes.includes(control.value)) return;
                selection = control.value;
                applyTheme();
                pickers.forEach(other => {
                    const select = other.querySelector('select[name="appearance"]');
                    if (select) select.value = selection;
                });
                if (authenticated && picker.matches('form')) {
                    picker.requestSubmit();
                } else if (!authenticated) {
                    try { window.localStorage.setItem(storageKey, selection); } catch {
                        // Keep the in-memory choice if storage is unavailable.
                    }
                }
            });
        });
    });
})();
