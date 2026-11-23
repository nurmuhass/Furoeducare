(() => {
    const workspace = document.querySelector('[data-admin-workspace]');
    if (!workspace) return;
    workspace.classList.add('admin-enhanced');
    const menu = workspace.querySelector('[data-admin-menu]');
    if (menu) {
        const closeMenu = workspace.querySelector('[data-admin-close-menu]');
        const close = () => {
            workspace.classList.remove('admin-menu-open');
            menu.setAttribute('aria-expanded', 'false');
            menu.focus();
        };
        menu.hidden = false;
        if (closeMenu) {
            closeMenu.hidden = false;
            closeMenu.addEventListener('click', close);
        }
        menu.addEventListener('click', () => {
            const open = workspace.classList.toggle('admin-menu-open');
            menu.setAttribute('aria-expanded', String(open));
            if (open) closeMenu?.focus();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && workspace.classList.contains('admin-menu-open')) {
                close();
            }
        });
    }
    workspace.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        const action = (event.submitter?.name === 'action' ? event.submitter.value : '') || form.querySelector('[name="action"]')?.value || '';
        const operation = (event.submitter?.name === 'operation' ? event.submitter.value : '') || form.querySelector('[name="operation"]')?.value || '';
        const decision = form.querySelector('[name="decision"]')?.value || '';
        const sensitive = /grant|publish|remove|delete|verify|suspend|restore|confirm|approve|reject|revoke|assign|release|place|decide|withdraw|close/i.test(action + ' ' + operation)
            || decision !== '' || /\/review$/.test(new URL(form.action, window.location.href).pathname);
        if ((form.hasAttribute('data-admin-confirm') || sensitive) && !window.confirm(form.dataset.adminConfirm || 'Confirm this management decision? Review the record and reason before continuing.')) event.preventDefault();
    });
})();
