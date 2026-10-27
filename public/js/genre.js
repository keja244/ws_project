(() => {
    'use strict';
    const { $, esc, SHOWS, byRating, cardHTML, getPage, setParams } = window.ITV;

    /* Pagination bergaya review (sama dengan pager Reviews di halaman detail) */
    const ARROW_L = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';
    const ARROW_R = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

    // 1 2 3 ... 9 10 (dengan titik-titik kalau halamannya banyak)
    const pageItems = (cur, total) => {
        if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
        const set = [...new Set([1, total, cur - 1, cur, cur + 1])]
            .filter((p) => p >= 1 && p <= total)
            .sort((a, b) => a - b);
        const out = [];
        let last = 0;
        set.forEach((p) => { if (p - last > 1) out.push('…'); out.push(p); last = p; });
        return out;
    };

    // dipakai untuk pager tile & pager hasil series
    const renderPager = (el, { page, pages, onChange }) => {
        el.hidden = pages <= 1;
        if (pages <= 1) { el.innerHTML = ''; el.onclick = null; return; }
        el.innerHTML =
            `<button type="button" class="rvpg" data-page="${page - 1}" aria-label="Previous page"${page === 1 ? ' disabled' : ''}>${ARROW_L}</button>` +
            pageItems(page, pages).map((p) => p === '…'
                ? '<span class="rvpg rvpg--gap">…</span>'
                : `<button type="button" class="rvpg${p === page ? ' is-active' : ''}" data-page="${p}"${p === page ? ' aria-current="page"' : ''}>${p}</button>`).join('') +
            `<button type="button" class="rvpg" data-page="${page + 1}" aria-label="Next page"${page === pages ? ' disabled' : ''}>${ARROW_R}</button>`;
        el.onclick = (e) => {
            const b = e.target.closest('.rvpg[data-page]');
            if (!b || b.disabled) return;
            const p = parseInt(b.dataset.page, 10);
            if (!p || p === page) return;
            onChange(p);
        };
    };

    const PER_PAGE = 21;                                    // hasil series per halaman
    const TILES_PER_PAGE = 12;                              // tile genre per halaman
    const SORTERS = {
        rating: byRating,
        votes: (a, b) => b.votes - a.votes,
        newest: (a, b) => b.start - a.start || byRating(a, b),
        seasons: (a, b) => b.seasons - a.seasons || byRating(a, b),
    };

    /* State */
    let current = null, sorted = [], page = 1;              // genre terpilih + hasil series
    let tilePage = 1, showAll = false;                      // daftar tile genre

    /* Kelompokkan per genre */
    const GENRES = new Map();
    SHOWS.forEach((s) => s.genres.forEach((g) => {
        if (!GENRES.has(g)) GENRES.set(g, []);
        GENRES.get(g).push(s);
    }));
    const genreList = [...GENRES.entries()]
        .map(([name, list]) => ({
            name,
            count: list.length,
            cover: ([...list].sort(byRating).find((s) => s.image) || {}).image || '',
        }))
        .sort((a, b) => a.name.localeCompare(b.name));

    const tileHTML = (g) => `
        <a class="genre-tile${g.name === current ? ' is-selected' : ''}" href="?genre=${encodeURIComponent(g.name)}" data-genre="${esc(g.name)}">
            ${g.cover ? `<img src="${esc(g.cover)}" alt="" loading="lazy" draggable="false" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}
            <span class="genre-tile__label">
                <span class="genre-tile__name">${esc(g.name)}</span>
                <span class="genre-tile__count">${g.count.toLocaleString()} shows</span>
            </span>
        </a>`;

    const grid = $('#genreGrid'), moreWrap = $('#genreMoreWrap'), moreBtn = $('#genreMore'), tilePager = $('#genreTilePager');
    const tilePages = Math.max(1, Math.ceil(genreList.length / TILES_PER_PAGE));

    // pager tile: style sama dengan pagination review di halaman detail 
    const renderTilePager = () => renderPager(tilePager, {
        page: tilePage, pages: tilePages,
        onChange: (p) => { tilePage = p; renderTiles(); },
    });

    /*  Daftar genre: pagination + Show all / Show less  */
    const renderTiles = () => {
        tilePage = Math.min(Math.max(tilePage, 1), tilePages);
        const from = (tilePage - 1) * TILES_PER_PAGE;
        const items = showAll ? genreList : genreList.slice(from, from + TILES_PER_PAGE);
        grid.innerHTML = items.map(tileHTML).join('');

        // genre sedikit -> tidak perlu pagination maupun tombol
        if (genreList.length <= TILES_PER_PAGE) {
            moreWrap.hidden = true;
            return;
        }

        moreBtn.textContent = showAll ? 'Show less' : 'Show all';
        moreBtn.setAttribute('aria-expanded', String(showAll));

        if (showAll) {
            tilePager.hidden = true;
            tilePager.innerHTML = '';
        } else {
            renderTilePager();
        }
    };

    moreBtn.addEventListener('click', () => {
        showAll = !showAll;
        if (!showAll) {
            // kembali ke mode halaman: tampilkan halaman yang memuat genre terpilih (kalau ada)
            const i = current ? genreList.findIndex((g) => g.name === current) : -1;
            tilePage = i >= 0 ? Math.floor(i / TILES_PER_PAGE) + 1 : 1;
        }
        renderTiles();
        if (!showAll) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    // pastikan tile genre terpilih terlihat (pindah ke halaman tile yang memuatnya)
    const revealGenre = (name) => {
        if (showAll) return;
        const i = genreList.findIndex((g) => g.name === name);
        if (i < 0) return;
        const p = Math.floor(i / TILES_PER_PAGE) + 1;
        if (p !== tilePage) { tilePage = p; renderTiles(); }
    };

    renderTiles();

    /*  Hasil per genre + search + pagination  */
    const section = $('#genreResults'), title = $('#resultsTitle'), status = $('#resultsStatus');
    const list = $('#genreShows'), sortSel = $('#sortSelect'), pager = $('#pager');
    const qForm = $('#genreSearch'), qInput = $('#genreQ'), qClear = $('#genreClear');

    const apply = () => {
        const q = qInput.value.trim().toLowerCase();
        let items = GENRES.get(current) || [];
        if (q) items = items.filter((s) => s.name.toLowerCase().includes(q) || s.cast.toLowerCase().includes(q));
        sorted = [...items].sort(SORTERS[sortSel.value]);
    };

    const render = (scroll) => {
        const total = sorted.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        page = Math.min(Math.max(page, 1), pages);
        const from = (page - 1) * PER_PAGE;
        const q = qInput.value.trim();

        list.innerHTML = total
            ? sorted.slice(from, from + PER_PAGE).map(cardHTML).join('')
            : `<p class="results__empty">No ${esc(current)} shows match “${esc(q)}”.</p>`;
        status.textContent = total
            ? `${(from + 1).toLocaleString()}–${Math.min(from + PER_PAGE, total).toLocaleString()} of ${total.toLocaleString()} show${total === 1 ? '' : 's'}`
            : '';
        renderPager(pager, {
            page, pages,
            onChange: (p) => { page = p; setParams({ page: p > 1 ? p : null }, 'push'); render(true); },
        });
        if (scroll) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const hideGenre = () => {
        current = null; section.hidden = true; list.innerHTML = ''; document.title = 'Genre — ITV';
        qInput.value = ''; qClear.hidden = true;
        grid.querySelectorAll('.genre-tile').forEach((t) => t.classList.remove('is-selected'));
    };

    const showGenre = (name, p = 1, scroll = true, q = qInput.value) => {
        if (!GENRES.has(name)) return hideGenre();
        current = name; page = p;
        qInput.value = q; qClear.hidden = !q;
        qInput.placeholder = `Search in ${name}...`;
        title.textContent = name;
        section.hidden = false;
        document.title = `${name} — ITV`;
        revealGenre(name);
        grid.querySelectorAll('.genre-tile').forEach((t) => t.classList.toggle('is-selected', t.dataset.genre === name));
        apply();
        render(scroll);
    };

    grid.addEventListener('click', (e) => {
        const tile = e.target.closest('.genre-tile');
        if (!tile) return;
        e.preventDefault();
        const name = tile.dataset.genre;
        setParams({ genre: name, page: null, q: null }, 'push');
        showGenre(name, 1, true, '');
    });

    sortSel.addEventListener('change', () => {
        if (!current) return;
        setParams({ page: null });
        showGenre(current, 1, false);
    });

    // search di dalam genre
    let t;
    const runSearch = () => {
        if (!current) return;
        const q = qInput.value.trim();
        qClear.hidden = !qInput.value;
        setParams({ q: q || null, page: null });
        page = 1; apply(); render(false);
    };
    qInput.addEventListener('input', () => { clearTimeout(t); t = setTimeout(runSearch, 150); });
    qForm.addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(t); runSearch(); });
    qClear.addEventListener('click', () => { qInput.value = ''; runSearch(); qInput.focus(); });

    window.addEventListener('popstate', () => {
        const sp = new URLSearchParams(location.search);
        const g = sp.get('genre');
        g ? showGenre(g, getPage(), false, sp.get('q') || '') : hideGenre();
    });

    // buka langsung dari URL: /genre?genre=Romance&page=2&q=love (juga dari klik genre di halaman detail)
    const sp = new URLSearchParams(location.search);
    const initial = sp.get('genre') || window.ITV_GENRE;
    if (initial) showGenre(initial, getPage(), true, sp.get('q') || '');
})();