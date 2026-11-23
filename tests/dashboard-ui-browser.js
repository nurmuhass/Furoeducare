/* Run in an isolated Chromium page containing an HTTP-generated dashboard fixture.
 * The harness evaluates this expression after loading assets; it is not app code. */
(async () => {
    const assert = (condition, message) => { if (!condition) throw new Error(message); };
    const workspace = document.querySelector('[data-dashboard-workspace]');
    assert(workspace?.classList.contains('dashboard-enhanced'), 'Dashboard enhancement loaded');
    const ids = [...document.querySelectorAll('[id]')].map(node => node.id);
    assert(new Set(ids).size === ids.length, 'Unique ids');
    assert(document.querySelectorAll('main').length === 1, 'One main landmark');
    assert(document.documentElement.scrollWidth <= innerWidth, 'No horizontal page overflow');
    assert(workspace.querySelectorAll('nav [aria-current="page"]').length === 1, 'Exactly one selected sidebar page');
    const menu = workspace.querySelector('[data-dashboard-menu]');
    const close = workspace.querySelector('[data-dashboard-close]');
    const body = workspace.querySelector('.dashboard-body');
    if (innerWidth <= 900) {
        menu.click();
        assert(menu.getAttribute('aria-expanded') === 'true' && body.inert && document.activeElement === close, 'Mobile opening focuses Close and disables background');
        const controls = [...workspace.querySelector('#dashboard-navigation').querySelectorAll('a[href],button:not([hidden]),select:not([hidden])')].filter(node => node.getClientRects().length);
        controls.at(-1).focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
        assert(document.activeElement === close, 'Mobile focus wraps forward');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
        assert(document.activeElement === controls.at(-1), 'Mobile focus wraps backward');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        assert(!body.inert && menu.getAttribute('aria-expanded') === 'false' && document.activeElement === menu, 'Escape restores background and trigger focus');
        menu.click(); close.click();
        assert(menu.getAttribute('aria-expanded') === 'false', 'Close button works');
    }
    const form = document.createElement('form');
    form.method = 'post';
    form.action = '/dashboard/admin/library';
    form.dataset.confirm = 'Synthetic browser check';
    form.innerHTML = '<input name="record" value="42"><button type="submit" name="action" value="publish">Publish</button>';
    workspace.querySelector('main').append(form);
    const button = form.querySelector('button');
    const submit = () => form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: button }));
    const dialog = workspace.querySelector('[data-dashboard-confirm]');
    submit();
    assert(dialog.open && form.getAttribute('aria-busy') !== 'true', 'Sensitive submission waits for confirmation');
    dialog.querySelector('[data-confirm-cancel]').click();
    assert(!dialog.open && !form.hasAttribute('aria-busy'), 'Cancellation leaves form usable');
    submit();
    let payload;
    form.requestSubmit = submitter => {
        payload = Object.fromEntries(new FormData(form, submitter));
        submit(); // dispatch without navigating away from this test document
    };
    dialog.querySelector('[data-confirm-proceed]').click();
    await Promise.resolve();
    assert(payload?.action === 'publish' && payload.record === '42', 'Confirmation preserves named submitter and fields');
    assert(form.getAttribute('aria-busy') === 'true' && !button.disabled && !workspace.querySelector('[data-dashboard-loading]').hidden, 'Actual submission shows busy state without dropping controls: ' + JSON.stringify({busy:form.getAttribute('aria-busy'), disabled:button.disabled, hidden:workspace.querySelector('[data-dashboard-loading]').hidden,action:form.action}));
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    assert(!form.hasAttribute('aria-busy') && !button.hasAttribute('aria-disabled'), 'Back restores form controls');
    form.removeAttribute('data-confirm');
    button.value = 'save';
    form.addEventListener('submit', event => event.preventDefault(), { once: true });
    submit();
    await Promise.resolve();
    assert(!form.hasAttribute('aria-busy'), 'Another cancelled handler never leaves a loading state');
    form.remove();
    const theme = workspace.querySelector('[data-theme-picker] select[name="appearance"]');
    const appearanceForm = theme.closest('form');
    // HTTP settings tests cover MySQL persistence; this static fixture checks
    // immediate application without following its real native POST navigation.
    if (appearanceForm) appearanceForm.requestSubmit = () => {};
    for (const choice of ['light', 'dark', 'system']) {
        theme.value = choice;
        theme.dispatchEvent(new Event('change', { bubbles: true }));
        assert(['light', 'dark'].includes(document.documentElement.dataset.bsTheme), 'Theme resolves ' + choice);
    }
    theme.value = innerWidth === 768 ? 'dark' : 'light';
    theme.dispatchEvent(new Event('change', { bubbles: true }));
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    // Bootstrap buttons transition colours briefly when the theme changes.
    await new Promise(resolve => setTimeout(resolve, 200));
    const primary = workspace.querySelector('.button-primary');
    if (primary) {
        const style = getComputedStyle(primary);
        const luminance = colour => {
            const rgb = colour.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => {
                v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4;
            });
            return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
        };
        const levels = [luminance(style.color), luminance(style.backgroundColor)].sort((a, b) => b - a);
        assert((levels[0] + .05) / (levels[1] + .05) >= 4.5, 'Primary button text contrast');
    }
    return JSON.stringify({ width: innerWidth, scroll: document.documentElement.scrollWidth, theme: document.documentElement.dataset.bsTheme,
        checks: 'landmarks, ids, navigation, focus, dialog, payload, loading, cancellation, Back, themes' });
})()
