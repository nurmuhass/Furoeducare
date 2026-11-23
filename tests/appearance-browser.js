/* Evaluated inside an isolated Chrome page containing an HTTP-generated fixture. */
(async () => {
    const assert = (condition, message) => { if (!condition) throw new Error(message); };
    const root = document.documentElement;
    assert(['light', 'dark'].includes(root.dataset.bsTheme), 'Theme applied');
    const ids = [...document.querySelectorAll('[id]')].map(node => node.id);
    assert(new Set(ids).size === ids.length, 'Unique field IDs');
    assert(document.querySelectorAll('main').length === 1, 'One main landmark');
    assert(root.scrollWidth <= innerWidth, 'No horizontal overflow');
    const pickers = [...document.querySelectorAll('[data-theme-picker]')];
    assert(pickers.length > 0, 'Appearance controls available');
    let submissions = 0;
    for (const picker of pickers) if (picker.matches('form')) picker.requestSubmit = () => { submissions++; };
    const select = pickers[0].querySelector('select[name="appearance"]');
    assert(select, 'Labelled appearance selector');
    for (const choice of ['light', 'dark', 'system']) {
        select.value = choice;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        assert(root.dataset.themePreference === choice, 'Preference applies: ' + choice);
        if (choice !== 'system') assert(root.dataset.bsTheme === choice, 'Explicit mode applies before navigation');
        for (const picker of pickers) assert(picker.querySelector('select[name="appearance"]').value === choice, 'All page controls stay synchronised');
    }
    if (root.hasAttribute('data-account-appearance')) assert(submissions === 3, 'Signed-in picker uses native persistence');
    else assert(localStorage.getItem('furoeducare-theme') === 'system', 'Guest preference stays local');
    select.value = innerWidth === 768 ? 'dark' : 'light';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await new Promise(resolve => setTimeout(resolve, 250));
    return JSON.stringify({width:innerWidth,scroll:root.scrollWidth,theme:root.dataset.bsTheme,checks:'public/account theme controls, native persistence, local storage, field ids, responsive layout'});
})()
