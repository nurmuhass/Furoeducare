const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/theme.js', 'utf8');
function page(account, saved, blocked = false) {
    const attrs = account === null ? {} : {'data-account-appearance': account};
    const handlers = {};
    const media = {matches: true, addEventListener(_, fn) {this.change = fn;}};
    const control = {value: '', addEventListener(event, fn) {this[event] = fn;}, closest() {return form;}};
    let submits = 0;
    const form = {removeAttribute() {}, requestSubmit() {submits++;}, querySelector() {return control;}, matches() {return account !== null;}};
    const document = {documentElement: {getAttribute: key => attrs[key] ?? null, setAttribute: (key, value) => attrs[key] = value},
        addEventListener: (event, fn) => handlers[event] = fn, querySelectorAll: () => [form], getElementById: () => control};
    const writes = [];
    vm.runInNewContext(source, {document, window: {matchMedia: () => media, localStorage: {
        getItem() {if (blocked) throw Error('blocked'); return saved;}, setItem(key, value) {if (blocked) throw Error('blocked'); writes.push(value);}
    }}});
    return {attrs, media, writes, ready() {handlers.DOMContentLoaded();}, choose(value) {control.value = value; control.change();}, submits: () => submits};
}
let guest = page(null, 'light');
assert.equal(guest.attrs['data-bs-theme'], 'light', 'Guest local preference applies before DOM ready.');
guest.ready(); guest.choose('dark');
assert.deepEqual(guest.writes, ['dark']);
for (const choice of ['light', 'dark', 'system']) {
    const account = page(choice, choice === 'light' ? 'dark' : 'light');
    assert.equal(account.attrs['data-bs-theme'], choice === 'system' ? 'dark' : choice, 'Authenticated account overrides guest storage.');
    account.ready(); account.choose('light');
    assert.equal(account.submits(), 1, 'Authenticated picker submits to the server.');
    assert.deepEqual(account.writes, [], 'Account changes never overwrite guest storage.');
}
guest = page(null, null, true);
assert.equal(guest.attrs['data-bs-theme'], 'dark');
guest.ready(); guest.choose('light');
assert.equal(guest.attrs['data-bs-theme'], 'light', 'Blocked storage still permits in-memory selection.');
guest.choose('system'); guest.media.matches = false; guest.media.change();
assert.equal(guest.attrs['data-bs-theme'], 'light', 'System mode tracks operating system changes.');
console.log('PASS guest/account precedence, early theme, native persistence, blocked storage and system changes.');
