/* Halaman person: /person?name=Bryan+Cranston[&role=actor|author][&page=2]
   Server sudah menyaring series yang dibintangi ATAU dibuat orang itu dan mengirim 'roles' per series.
   Di sini: tab peran (All / Acting / Created by), urut rating, pagination. */
(() => {
    'use strict';
    const { $, esc, SHOWS, USING_PLACEHOLDER, byRating, cardHTML, renderPager, getPage, setParams } = window.ITV;

    const PER_PAGE = 21;
    const name = window.ITV_PERSON || '';
    const items = USING_PLACEHOLDER ? [] : [...SHOWS].sort(byRating);

    const title = $('#pTitle'), status = $('#pStatus'), tabs = $('#pTabs'), list = $('#pList'), pager = $('#pPager'), back = $('#pBack');

    // "Back" kembali ke halaman sebelumnya 
    back.addEventListener('click', (e) => {
        if (history.length > 1 && document.referrer.startsWith(location.origin)) {
            e.preventDefault();
            history.back();
        }
    });

    if (!name) {
        title.textContent = 'No person selected';
        status.textContent = 'Open a series and click a cast member or creator.';
        return;
    }

    title.textContent = name;
    document.title = `${name} — ITV`;

    const acted = items.filter((s) => s.roles.includes('actor'));
    const created = items.filter((s) => s.roles.includes('author'));
    const ROLES = { all: items, actor: acted, author: created };

    let role = new URLSearchParams(location.search).get('role');
    if (!ROLES[role] || !ROLES[role].length) role = 'all';
    let page = getPage();

    // tab hanya muncul kalau orang ini punya dua peran (mis. aktor sekaligus pembuat)
    const showTabs = acted.length > 0 && created.length > 0;
    const renderTabs = () => {
        tabs.hidden = !showTabs;
        if (!showTabs) return;
        tabs.innerHTML = [['all', 'All', items.length], ['actor', 'Acting', acted.length], ['author', 'Created by', created.length]]
            .map(([k, l, n]) => `<button type="button" class="ptab${k === role ? ' is-on' : ''}" data-role="${k}">${l} <small>${n}</small></button>`).join('');
    };
    tabs.addEventListener('click', (e) => {
        const b = e.target.closest('[data-role]'); if (!b) return;
        role = b.dataset.role; page = 1;
        setParams({ role: role === 'all' ? null : role, page: null }, 'push');
        render(false);
    });

    const summary = () => {
        const parts = [];
        if (acted.length) parts.push(`Acted in ${acted.length}`);
        if (created.length) parts.push(`Created ${created.length}`);
        return parts.join(' · ');
    };

    const render = (scroll) => {
        const data = ROLES[role];
        const total = data.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        page = Math.min(Math.max(page, 1), pages);
        const from = (page - 1) * PER_PAGE;

        renderTabs();
        list.innerHTML = total
            ? data.slice(from, from + PER_PAGE).map(cardHTML).join('')
            : `<p class="results__empty">No series found for “${esc(name)}”.</p>`;
        status.textContent = total
            ? `${total.toLocaleString()} series${summary() ? ` · ${summary()}` : ''} · ${(from + 1).toLocaleString()}–${Math.min(from + PER_PAGE, total).toLocaleString()} shown`
            : '';
        renderPager(pager, {
            page, pages,
            onChange: (p) => { page = p; setParams({ page: p > 1 ? p : null }, 'push'); render(true); },
        });
        if (scroll) window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    window.addEventListener('popstate', () => {
        const r = new URLSearchParams(location.search).get('role');
        role = ROLES[r] && ROLES[r].length ? r : 'all';
        page = getPage();
        render(false);
    });
    render(false);
})();