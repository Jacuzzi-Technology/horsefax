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

// Pedigree autofill (allbreedpedigree-style): type a name, pick a horse Horsefax knows, and its ancestors fill in behind it.
document.querySelectorAll('[data-pedigree-form]').forEach((form) => {
    const rows = {};
    form.querySelectorAll('[data-ped-row]').forEach((r) => (rows[r.dataset.pedRow] = r));
    const field = (row, f) => row.querySelector(`[data-field="${f}"]`);
    const esc = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

    const setRow = (pos, data, note) => {
        const row = rows[pos];
        if (!row) return;
        field(row, 'name').value = data?.name ?? '';
        const reg = field(row, 'registry');
        reg.value = data?.registry && [...reg.options].some((o) => o.value === data.registry) ? data.registry : '';
        field(row, 'registration_number').value = data?.registration_number ?? '';
        field(row, 'foal_year').value = data?.foal_year ?? '';
        field(row, 'color').value = data?.color ?? '';
        field(row, 'linked_horse_id').value = data?.linked_horse_id ?? '';
        row.querySelector('[data-ped-note]').textContent = note ?? '';
        syncPed(pos);
        if (note) {
            row.classList.add('bg-gold/10');
            setTimeout(() => row.classList.remove('bg-gold/10'), 2500);
        }
    };

    // Tabs, per-tab counts, and the "Parents of …" names under later generations.
    const tabs = form.querySelectorAll('[data-ped-tab]');
    const show = (g) => {
        tabs.forEach((t) => {
            const on = t.dataset.pedTab === String(g);
            t.setAttribute('aria-selected', on);
            t.classList.toggle('bg-paper', on); t.classList.toggle('border-line', on); t.classList.toggle('text-ink', on);
            t.classList.toggle('border-transparent', !on); t.classList.toggle('text-muted', !on);
        });
        form.querySelectorAll('[data-ped-panel]').forEach((p) => (p.hidden = p.dataset.pedPanel !== String(g)));
    };
    tabs.forEach((t) => t.addEventListener('click', () => show(t.dataset.pedTab)));
    form.querySelectorAll('[data-ped-go]').forEach((b) => b.addEventListener('click', () => { show(b.dataset.pedGo); form.scrollIntoView({ behavior: 'smooth', block: 'start' }); }));
    function syncPed(pos) {
        const g = pos.length;
        const total = Object.keys(rows).filter((p) => p.length === g);
        const n = total.filter((p) => field(rows[p], 'name').value.trim()).length;
        const c = form.querySelector(`[data-ped-count="${g}"]`);
        if (c) c.textContent = `${n}/${total.length}`;
        const label = form.querySelector(`[data-ped-child="${pos}"]`);
        if (label) label.textContent = field(rows[pos], 'name').value.trim() || 'not entered yet';
    }

    Object.entries(rows).forEach(([pos, row]) => {
        const input = field(row, 'name');
        input.addEventListener('change', () => syncPed(pos));
        const list = row.querySelector('[data-ped-list]');
        let timer, results = [], active = -1;

        const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; };
        const render = () => {
            if (!results.length) {
                list.innerHTML = `<li class="px-3 py-2 font-sans text-[13px] text-muted">Not on Horsefax yet. Keep typing and enter the details yourself.</li>`;
            } else {
                list.innerHTML = results.map((r, i) => `
                    <li role="option" data-i="${i}" class="px-3 py-2 cursor-pointer border-b border-line last:border-0 ${i === active ? 'bg-gold/15' : 'hover:bg-cream'}">
                        <span class="text-[16px] text-ink">${esc(r.name)}</span>
                        <span class="block font-sans text-[12px] text-muted">${esc([r.registry && r.registration_number ? r.registry + ' ' + r.registration_number : null, r.foal_year, r.color].filter(Boolean).join(' · '))}</span>
                        <span class="block font-sans text-[11px] text-gold-dark">${r.kind === 'record' ? 'Horsefax record ' + esc(r.horsefax_id) : 'From pedigrees on Horsefax'}${r.ancestors ? ' · ' + r.ancestors + ' ancestors on file' : ''}</span>
                    </li>`).join('');
            }
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };

        const choose = async (r) => {
            close();
            // A new horse in this slot replaces everything behind it.
            Object.keys(rows).filter((p) => p.startsWith(pos) && p !== pos).forEach((p) => setRow(p, null));
            setRow(pos, { ...r, linked_horse_id: r.kind === 'record' ? r.id : '' }, 'Picked from Horsefax');
            try {
                const res = await fetch(`${form.dataset.ancestry}?kind=${r.kind}&id=${r.id}`, { headers: { Accept: 'application/json' } });
                const { ancestors } = await res.json();
                let n = 0;
                Object.entries(ancestors || {}).forEach(([rel, a]) => {
                    if (rows[pos + rel]) { setRow(pos + rel, a, 'Filled from Horsefax'); n++; }
                });
                row.querySelector('[data-ped-note]').textContent = n ? `Picked from Horsefax · ${n} ancestors filled in the next tabs` : 'Picked from Horsefax';
            } catch (e) { /* the pick itself still stands */ }
        };

        input.addEventListener('input', () => {
            syncPed(pos);
            field(row, 'linked_horse_id').value = '';
            row.querySelector('[data-ped-note]').textContent = '';
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 2) return close();
            timer = setTimeout(async () => {
                try {
                    const res = await fetch(`${form.dataset.lookup}?pos=${pos}&q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                    if (!res.ok) return close();
                    results = (await res.json()).results || [];
                    active = -1;
                    if (input.value.trim() === q) render();
                } catch (e) { close(); }
            }, 250);
        });
        input.addEventListener('keydown', (e) => {
            if (list.hidden || !results.length) return;
            if (e.key === 'ArrowDown') { active = Math.min(active + 1, results.length - 1); render(); e.preventDefault(); }
            else if (e.key === 'ArrowUp') { active = Math.max(active - 1, 0); render(); e.preventDefault(); }
            else if (e.key === 'Enter' && active >= 0) { choose(results[active]); e.preventDefault(); }
            else if (e.key === 'Escape') close();
        });
        list.addEventListener('mousedown', (e) => {
            const li = e.target.closest('[data-i]');
            if (li) { e.preventDefault(); choose(results[+li.dataset.i]); }
        });
        input.addEventListener('blur', () => setTimeout(close, 150));
    });
});

// Search suggestions: <input data-suggest="/search/suggest"> inside a relative wrapper shows matching horses as you type.
document.querySelectorAll('input[data-suggest]').forEach((input, n) => {
    const list = document.createElement('ul');
    list.id = `suggest-${n}`;
    list.setAttribute('role', 'listbox');
    list.className = 'suggest-list';
    list.hidden = true;
    input.after(list);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('autocomplete', 'off');
    let timer, active = -1, items = [], seq = 0;

    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; input.removeAttribute('aria-activedescendant'); };
    const highlight = (i) => {
        const opts = list.querySelectorAll('[role="option"]');
        opts.forEach((o, k) => o.setAttribute('aria-selected', k === i ? 'true' : 'false'));
        active = i;
        if (opts[i]) input.setAttribute('aria-activedescendant', opts[i].id);
    };
    const render = (q) => {
        list.replaceChildren();
        const add = (id, html, href) => {
            const li = document.createElement('li');
            li.id = `${list.id}-${id}`;
            li.setAttribute('role', 'option');
            li.innerHTML = html;
            li.addEventListener('mousedown', (e) => { e.preventDefault(); href ? (location.href = href) : input.form.submit(); });
            list.append(li);
        };
        const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
        items.forEach((h, i) => add(i, `<span class="suggest-name">${esc(h.name)}</span>${h.detail ? `<span class="suggest-detail">${esc(h.detail)}</span>` : ''}`, h.url));
        add('all', `<span class="suggest-all">${items.length ? 'See all results for' : 'Search for'} “${esc(q)}”</span>`, null);
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        active = -1;
    };
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 3) { close(); return; }
        timer = setTimeout(async () => {
            const mine = ++seq;
            try {
                const res = await fetch(`${input.dataset.suggest}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                if (!res.ok || mine !== seq) return;
                items = await res.json();
                if (mine === seq && input.value.trim() === q) render(q);
            } catch { /* offline: the form still works */ }
        }, 180);
    });
    input.addEventListener('keydown', (e) => {
        const opts = list.querySelectorAll('[role="option"]');
        if (list.hidden || !opts.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); highlight((active + 1) % opts.length); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); highlight((active - 1 + opts.length) % opts.length); }
        else if (e.key === 'Escape') close();
        else if (e.key === 'Enter' && active >= 0 && active < items.length) { e.preventDefault(); location.href = items[active].url; }
    });
    input.addEventListener('blur', () => setTimeout(close, 120));
});
