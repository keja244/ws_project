
(() => {
    'use strict';
    const { $, esc, SHOWS, byVotes } = window.ITV;

    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const LIMIT = 7;
    const POPULAR = [...SHOWS].sort(byVotes);

    const highlight = (text, q) => {
        if (!q || !text) return esc(text);
        const i = text.toLowerCase().indexOf(q);
        if (i < 0) return esc(text);
        return esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + q.length)) + '</mark>' + esc(text.slice(i + q.length));
    };

    const rowHTML = (s, q) => `
        <a class="sp-row" href="${DETAIL_URL}${encodeURIComponent(s.id)}">
            <div class="sp-row__title">${highlight(s.name, q)}${s.start ? `<span class="sp-row__year">(${s.start})</span>` : ''}</div>
            <div class="sp-row__sub">
                <span class="sp-row__cast">${highlight(s.cast, q)}</span>
                <span class="sp-row__genre">${highlight(s.genre, q)}</span>
            </div>
        </a>`;

    const score = (s, q) => {
        const name = s.name.toLowerCase();
        if (name.startsWith(q)) return 3;
        if (name.includes(q)) return 2;
        if (String(s.start) === q || s.cast.toLowerCase().includes(q) || s.genre.toLowerCase().includes(q)) return 1;
        return 0;
    };

    const input = $('#navInput'), form = $('#navSearch'), list = $('#spList');

    const run = () => {
        const raw = input.value.trim(), q = raw.toLowerCase();
        const all = q
            ? SHOWS.map((s) => [s, score(s, q)]).filter(([, sc]) => sc > 0)
                   .sort((a, b) => b[1] - a[1] || b[0].votes - a[0].votes).map(([s]) => s)
            : POPULAR;
        const top = all.slice(0, LIMIT);

        list.innerHTML = top.length
            ? `<div class="sp__head">${q ? `Showing the top results for “${esc(raw)}”` : 'Most popular series'}</div>`
                + top.map((s) => rowHTML(s, q)).join('')
            : '<p class="sp__empty">No series found. Try another title, year, cast, or genre.</p>';

        // simpan kata kunci di URL (tanpa reload) supaya bisa di-share / di-refresh
        const url = new URL(location.href);
        raw ? url.searchParams.set('q', raw) : url.searchParams.delete('q');
        history.replaceState(null, '', url);
    };

    let t;
    input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 150); });
    form.addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(t); run(); });

    run();
})();