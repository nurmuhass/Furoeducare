(() => {
    'use strict';
    const workspace = document.querySelector('[data-dashboard-workspace]');
    if (!workspace) return;
    workspace.classList.add('dashboard-enhanced');
    const menu = workspace.querySelector('[data-dashboard-menu]');
    const sidebar = workspace.querySelector('#dashboard-navigation');
    const closeButton = workspace.querySelector('[data-dashboard-close]');
    const body = workspace.querySelector('.dashboard-body');
    const mobile = window.matchMedia('(max-width: 900px)');
    let scrollTop = 0;
    const close = (focus = true) => {
        const wasOpen = workspace.classList.contains('dashboard-menu-open');
        workspace.classList.remove('dashboard-menu-open');
        menu.setAttribute('aria-expanded', 'false');
        body.inert = false;
        document.documentElement.style.overflow = '';
        if (wasOpen) window.scrollTo(0, scrollTop);
        if (focus && wasOpen) menu.focus();
    };
    menu.hidden = false;
    closeButton.hidden = false;
    menu.addEventListener('click', () => {
        if (workspace.classList.contains('dashboard-menu-open')) return close();
        scrollTop = window.scrollY;
        workspace.classList.add('dashboard-menu-open');
        menu.setAttribute('aria-expanded', 'true');
        body.inert = true;
        document.documentElement.style.overflow = 'hidden';
        closeButton.focus();
    });
    closeButton.addEventListener('click', () => close());
    mobile.addEventListener('change', () => {
        const hiddenFocus = sidebar.contains(document.activeElement) || document.activeElement === menu;
        close(false);
        if (hiddenFocus) {
            if (mobile.matches) menu.focus();
            else sidebar.querySelector('a[href]')?.focus();
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            if (workspace.classList.contains('dashboard-menu-open')) close();
            else {
                const account = workspace.querySelector('[data-dashboard-account][open]');
                if (account) { account.open = false; account.querySelector('summary').focus(); }
            }
        }
        if (event.key === 'Tab' && workspace.classList.contains('dashboard-menu-open')) {
            const controls = [...sidebar.querySelectorAll('a[href],button:not([hidden]),select:not([hidden])')].filter(node => node.getClientRects().length);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    const dialog = workspace.querySelector('[data-dashboard-confirm]');
    let pending = null;
    let approved = null;
    dialog.querySelector('[data-confirm-cancel]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { pending = null; });
    dialog.querySelector('[data-confirm-proceed]').addEventListener('click', () => {
        if (!pending) return;
        const { form, submitter } = pending;
        approved = form;
        dialog.close();
        // requestSubmit preserves validation and the clicked button's name/value.
        form.requestSubmit(submitter || undefined);
        approved = null;
    });
    const loading = workspace.querySelector('[data-dashboard-loading]');
    const busy = new Set();
    // Named action buttons shadow HTMLFormElement.action in the browser DOM.
    const actionPath = form => new URL(form.getAttribute('action') || location.href, location.href).pathname;
    workspace.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
        if (busy.has(form)) { event.preventDefault(); return; }
        const post = form.method.toLowerCase() === 'post';
        const action = (event.submitter?.name === 'action' ? event.submitter.value : '') || form.querySelector('[name="action"]')?.value || '';
        const operation = (event.submitter?.name === 'operation' ? event.submitter.value : '') || form.querySelector('[name="operation"]')?.value || '';
        const sensitive = post && (form.hasAttribute('data-admin-confirm') || form.hasAttribute('data-confirm')
            || /grant|publish|remove|delete|verify|suspend|restore|confirm|approve|reject|revoke|assign|release|place|decide|withdraw|close/i.test(action + ' ' + operation)
            || !!form.querySelector('[name="decision"]')?.value || /\/review$/.test(actionPath(form)));
        if (sensitive && approved !== form) {
            const message = form.dataset.adminConfirm || form.dataset.confirm || 'Review the selected record and decision before continuing.';
            if (typeof dialog.showModal === 'function') {
                event.preventDefault();
                pending = { form, submitter: event.submitter };
                dialog.querySelector('[data-confirm-description]').textContent = message;
                dialog.showModal();
                return;
            }
            if (!window.confirm(message)) { event.preventDefault(); return; }
        }
        // Defer until other submit listeners have had a chance to cancel navigation.
        queueMicrotask(() => {
            if (event.defaultPrevented || form.target === '_blank' || form.hasAttribute('data-no-loading')) return;
            // Download responses keep the current page; they must never lock its form.
            if (/\/(?:download|preview)(?:\/|$)/i.test(actionPath(form))) return;
            busy.add(form);
            form.setAttribute('aria-busy', 'true');
            loading.hidden = false;
            // Do not disable named controls: browsers serialize them after submit handlers.
            if (event.submitter) event.submitter.setAttribute('aria-disabled', 'true');
        });
    });
    window.addEventListener('pageshow', () => {
        for (const form of busy) { form.removeAttribute('aria-busy'); form.querySelectorAll('[aria-disabled="true"]').forEach(node => node.removeAttribute('aria-disabled')); }
        busy.clear();
        loading.hidden = true;
    });
    workspace.querySelector('[data-error-summary]')?.focus();
})();
