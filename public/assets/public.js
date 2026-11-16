(() => {
    const header = document.querySelector('[data-site-header]');
    const toggle = header.querySelector('[data-menu-toggle]');
    const setMenu = (open) => {
        header.toggleAttribute('data-menu-open', open);
        toggle.setAttribute('aria-expanded', String(open));
    };
    toggle.addEventListener('click', () => setMenu(toggle.getAttribute('aria-expanded') === 'false'));
    header.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setMenu(false);
            toggle.focus();
        }
    });
    toggle.hidden = false;
    header.setAttribute('data-enhanced', '');

    const updateField = (field) => {
        const error = document.getElementById(field.id + '-error');
        let message = '';
        if (field.value.trim() === '') {
            message = field instanceof HTMLSelectElement ? 'Choose an option.' : 'Complete this field.';
        } else if (field.validity.typeMismatch) {
            message = 'Enter an email address in the format name@example.com.';
        }
        if (message) {
            field.setAttribute('aria-invalid', 'true');
        } else {
            field.removeAttribute('aria-invalid');
        }
        error.textContent = message;
        error.hidden = !message;
        return !message;
    };

    document.querySelectorAll('[data-preview-form]').forEach((form) => {
        const fields = [...form.querySelectorAll('input, select, textarea')];
        const fieldset = form.querySelector('fieldset');
        const button = form.querySelector('button[type="submit"]');
        const summary = form.querySelector('[data-form-summary]');
        const status = form.querySelector('[data-form-status]');
        let checkTimer;
        const finishCheck = () => {
            window.clearTimeout(checkTimer);
            checkTimer = undefined;
            fieldset.removeAttribute('aria-busy');
            button.removeAttribute('aria-disabled');
        };

        form.addEventListener('input', (event) => {
            if (event.target.hasAttribute('aria-invalid')) {
                updateField(event.target);
                summary.hidden = !fields.some((field) => field.hasAttribute('aria-invalid'));
            }
            if (checkTimer !== undefined) {
                finishCheck();
            }
            status.textContent = '';
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (checkTimer !== undefined) {
                return;
            }

            const invalid = fields.filter((field) => !updateField(field));
            summary.hidden = invalid.length === 0;
            if (invalid.length > 0) {
                status.textContent = '';
                summary.textContent = 'Check the highlighted fields to continue.';
                invalid[0].focus();
                return;
            }

            fieldset.setAttribute('aria-busy', 'true');
            button.setAttribute('aria-disabled', 'true');
            status.textContent = 'Checking your preview…';
            checkTimer = window.setTimeout(() => {
                finishCheck();
                status.textContent = form.dataset.ready;
            }, 450);
        });
        fieldset.disabled = false;
    });
})();

const teacherEntries = document.querySelector('[data-teacher-entries]');
const teacherEntryTemplate = document.querySelector('[data-teacher-entry-template]');
const addTeacherEntry = document.querySelector('[data-add-teacher-entry]');

if (teacherEntries && teacherEntryTemplate && addTeacherEntry) {
    const limit = Number(addTeacherEntry.dataset.entryLimit);
    addTeacherEntry.hidden = false;
    addTeacherEntry.addEventListener('click', () => {
        const index = teacherEntries.children.length;
        if (index >= limit) {
            return;
        }

        const row = document.createElement('template');
        row.innerHTML = teacherEntryTemplate.innerHTML
            .replaceAll('ROWINDEX', String(index))
            .replaceAll('ROWNUMBER', String(index + 1));
        const firstInput = row.content.querySelector('input');
        teacherEntries.append(row.content);
        addTeacherEntry.hidden = teacherEntries.children.length >= limit;
        firstInput.focus();
    });
}
