(() => {
    const workspace = document.querySelector('[data-teacher-workspace]');
    const toggle = workspace.querySelector('[data-teacher-menu-toggle]');
    const account = workspace.querySelector('[data-teacher-account]');
    const summary = account.querySelector('summary');
    const compact = window.matchMedia('(max-width: 63.99rem)');

    const setMenu = (open, restoreFocus = false) => {
        workspace.toggleAttribute('data-menu-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        if (restoreFocus) {
            toggle.focus();
        }
    };

    workspace.setAttribute('data-menu-enhanced', '');
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        account.open = false;
        setMenu(!workspace.hasAttribute('data-menu-open'));
    });
    account.addEventListener('toggle', () => {
        if (account.open) {
            setMenu(false);
        }
    });
    compact.addEventListener('change', () => {
        const navigation = document.getElementById('teacher-navigation');
        setMenu(false, compact.matches && navigation.contains(document.activeElement));
    });
    document.addEventListener('click', (event) => {
        if (account.open && !account.contains(event.target)) {
            account.open = false;
        }
    });
    workspace.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (account.open) {
            account.open = false;
            summary.focus();
        } else if (compact.matches && workspace.hasAttribute('data-menu-open')) {
            setMenu(false, true);
        }
    });
})();
