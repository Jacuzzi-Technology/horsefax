// Copy-to-clipboard buttons: <button data-copy="text">
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    navigator.clipboard?.writeText(b.dataset.copy).then(() => {
        const t = b.textContent;
        b.textContent = 'Copied';
        setTimeout(() => (b.textContent = t), 1600);
    });
});
// Confirm before irreversible actions: <form data-confirm="Are you sure?">
document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-confirm]');
    if (f && !confirm(f.dataset.confirm)) e.preventDefault();
});
// Visibility selects save on change.
document.addEventListener('change', (e) => {
    if (e.target.matches('select[data-autosubmit]')) e.target.form.submit();
});
// History entry form: show the fields for the chosen category, and only that category's types.
function syncEventForm(form) {
    const cat = form.querySelector('[data-category]')?.value;
    form.querySelectorAll('[data-for]').forEach((g) => (g.hidden = !g.dataset.for.split(' ').includes(cat)));
    const type = form.querySelector('[data-type]');
    type?.querySelectorAll('option[data-cat]').forEach((o) => {
        o.hidden = o.dataset.cat !== cat;
        if (o.hidden && o.selected) type.value = '';
    });
}
document.querySelectorAll('[data-event-form]').forEach((form) => {
    syncEventForm(form);
    form.querySelector('[data-category]')?.addEventListener('change', (e) => {
        syncEventForm(form);
        const vis = form.querySelector('select[name="visibility"]');
        const def = e.target.selectedOptions[0]?.dataset.defaultVis;
        if (vis && def) vis.value = def;
    });
});

// Add-a-horse form: one row per registry, "Add another registration" clones the last row.
document.querySelectorAll('[data-registrations]').forEach((list) => {
    const form = list.closest('form');
    const renumber = () => list.querySelectorAll('[data-registration-row]').forEach((row, i) => {
        row.querySelectorAll('input').forEach((input) => (input.name = input.name.replace(/registrations\[\d+\]/, `registrations[${i}]`)));
        row.querySelector('[data-remove-registration]').hidden = list.children.length === 1;
    });
    form.querySelector('[data-add-registration]')?.addEventListener('click', () => {
        const row = list.lastElementChild.cloneNode(true);
        row.querySelectorAll('input').forEach((input) => (input.value = ''));
        list.appendChild(row);
        renumber();
        row.querySelector('input').focus();
    });
    list.addEventListener('click', (e) => {
        if (!e.target.closest('[data-remove-registration]') || list.children.length === 1) return;
        e.target.closest('[data-registration-row]').remove();
        renumber();
    });
    renumber();
});

// Breed / color lists: "Other" shows a text box.
document.querySelectorAll('[data-choice]').forEach((wrap) => {
    const select = wrap.querySelector('[data-choice-select]');
    const other = wrap.querySelector('[data-choice-other]');
    select.addEventListener('change', () => {
        other.hidden = select.value !== '__other';
        if (!other.hidden) other.focus();
    });
});
