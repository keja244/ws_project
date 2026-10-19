/* =========================================================
   Halaman Search — live search, hasil berupa daftar
   Data: window.ITV_SHOWS (dari backend) atau PLACEHOLDER (Breaking Bad x30).
   Cari di: judul, tahun, cast, genre. Dimuat bertahap 20 per 20.
   ========================================================= */
(() => {
    'use strict';

    const BREAKING_BAD = {
        name: 'Breaking Bad',
        aggregateRating: 9.5,
        interactionCount: 2680071,
        startDate: 2008,
        endDate: 2013,
        genre: 'Crime, Drama, Thriller',
        actor: 'Bryan Cranston, Aaron Paul, Anna Gunn',
    };
    const PLACEHOLDER = Array.from({ length: 30 }, (_, i) => ({ ...BREAKING_BAD, id: `tt0903747-${i + 1}` }));

    const $ = (s) => document.querySelector(s);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const PAGE = 20;

    const normalize = (r) => ({
        id: r.id || r.name,
        name: r.name || 'Untitled',
        votes: parseInt(r.interactionCount, 10) || 0,
        start: parseInt(r.startDate, 10) || 0,
        genre: String(r.genre || '').split(',').map((g) => g.trim()).filter(Boolean).join(', '),
        cast: String(r.actor || '').split(',').map((g) => g.trim()).filter(Boolean).join(', '),
    });

    const useReal = Array.isArray(window.ITV_SHOWS) && window.ITV_SHOWS.length;
    const SHOWS = (useReal ? window.ITV_SHOWS : PLACEHOLDER).map(normalize).sort((a, b) => b.votes - a.votes);

    /* ---------- Sorot kata yang cocok ---------- */
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

    /* ---------- Cari ---------- */
    const score = (s, q) => {
        const name = s.name.toLowerCase();
        if (name.startsWith(q)) return 3;
        if (name.includes(q)) return 2;
        if (String(s.start) === q || s.cast.toLowerCase().includes(q) || s.genre.toLowerCase().includes(q)) return 1;
        return 0;
    };

    const input = $('#spInput'), list = $('#spList'), status = $('#spStatus'), more = $('#spMore'), btn = $('#spBtn');
    let results = [], shown = 0, query = '';

    const renderMore = () => {
        const next = results.slice(shown, shown + PAGE);
        list.insertAdjacentHTML('beforeend', next.map((s) => rowHTML(s, query)).join(''));
        shown += next.length;
        more.hidden = shown >= results.length;
    };

    const run = () => {
        query = input.value.trim().toLowerCase();
        results = query
            ? SHOWS.map((s) => [s, score(s, query)]).filter(([, sc]) => sc > 0)
                   .sort((a, b) => b[1] - a[1] || b[0].votes - a[0].votes).map(([s]) => s)
            : SHOWS;
        shown = 0;
        list.innerHTML = '';

        if (!results.length) {
            list.innerHTML = '<p class="sp__empty">No series found. Try another title, year, cast, or genre.</p>';
            list.style.display = 'block';
            more.hidden = true;
        } else {
            list.style.display = '';
            renderMore();
        }

        status.innerHTML = query
            ? `<b>${results.length}</b> result${results.length === 1 ? '' : 's'} for “${esc(input.value.trim())}”`
            : 'Most popular series';

        // simpan kata kunci di URL (tanpa reload) supaya bisa di-share / di-refresh
        const url = new URL(location.href);
        query ? url.searchParams.set('q', input.value.trim()) : url.searchParams.delete('q');
        history.replaceState(null, '', url);
    };

    let t;
    input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 150); });
    $('#spForm').addEventListener('submit', (e) => { e.preventDefault(); run(); });
    btn.addEventListener('click', renderMore);

    // muat otomatis saat scroll mendekati bawah
    if ('IntersectionObserver' in window) {
        new IntersectionObserver((en) => { if (en[0].isIntersecting && shown < results.length) renderMore(); }, { rootMargin: '300px' }).observe(more);
    }

    // kata kunci dari landing: /search?q=...
    const q0 = new URLSearchParams(location.search).get('q');
    if (q0) input.value = q0;
    run();
})();