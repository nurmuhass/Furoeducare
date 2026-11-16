(() => {
    const storageKey = 'furoeducare-theme';
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    const themes = ['system', 'light', 'dark'];
    let selection = 'system';

    try {
        const saved = window.localStorage.getItem(storageKey);
        if (themes.includes(saved)) {
            selection = saved;
        }
    } catch {
        // The system preference is available without persistent storage.
    }

    const applyTheme = () => {
        const theme = selection === 'system'
            ? (systemTheme.matches ? 'dark' : 'light')
            : selection;
        document.documentElement.setAttribute('data-bs-theme', theme);
    };

    applyTheme();
    systemTheme.addEventListener('change', applyTheme);

    document.addEventListener('DOMContentLoaded', () => {
        const control = document.getElementById('theme');
        if (!control) {
            return;
        }
        control.value = selection;
        control.addEventListener('change', () => {
            selection = control.value;
            applyTheme();
            try {
                window.localStorage.setItem(storageKey, selection);
            } catch {
                // The selection still applies to the current page.
            }
        });
        control.closest('[data-theme-picker]')?.removeAttribute('hidden');
    });
})();
