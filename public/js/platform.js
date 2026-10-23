/* Halaman Platform: /platform?platform=AMC&page=2&q=breaking
   Struktur sama dengan genre.js. */
(() => {
    'use strict';
    const { $, esc, SHOWS, USING_PLACEHOLDER, byRating, cardHTML, renderPager, getPage, setParams } = window.ITV;

    const PER_PAGE = 21;
    const TILES_PER_PAGE = 12;                              
    const SORTERS = {
        rating: byRating,
        votes: (a, b) => b.votes - a.votes,
        newest: (a, b) => b.start - a.start || byRating(a, b),
        seasons: (a, b) => b.seasons - a.seasons || byRating(a, b),
    };

    /* Kelompokkan per platform */
    const PLATFORMS = new Map();
    (USING_PLACEHOLDER ? [] : SHOWS).forEach((s) => (s.platforms || []).forEach((p) => {
        if (!PLATFORMS.has(p)) PLATFORMS.set(p, []);
        PLATFORMS.get(p).push(s);
    }));

    // yang paling banyak show-nya di depan
    const platformList = [...PLATFORMS.entries()]
        .map(([name, list]) => ({
            name,
            count: list.length,
            cover: ([...list].sort(byRating).find((s) => s.image) || {}).image || '',
        }))
        .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));

    const tileHTML = (g) => `
        <a class="genre-tile" href="?platform=${encodeURIComponent(g.name)}" data-platform="${esc(g.name)}">
            ${g.cover ? `<img src="${esc(g.cover)}" alt="" loading="lazy" draggable="false" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}
            <span class="genre-tile__label">
                <span class="genre-tile__name">${esc(g.name)}</span>
                <span class="genre-tile__count">${g.count.toLocaleString()} show${g.count === 1 ? '' : 's'}</span>
            </span>
        </a>`;

    const grid = $('#platformGrid'), tilesPager = $('#platformTilesPager');

    if (!platformList.length) {
        grid.innerHTML = '<p class="results__empty">No platform data available yet.</p>';
        return;
    }

    /*  Pagination untuk daftar tile platform */
    const TILE_PAGES = Math.ceil(platformList.length / TILES_PER_PAGE);
    let tilePage = 1;

    const markSelected = () => grid.querySelectorAll('.genre-tile')
        .forEach((t) => t.classList.toggle('is-selected', t.dataset.platform === current));

    const renderTiles = (scroll) => {
        tilePage = Math.min(Math.max(tilePage, 1), TILE_PAGES);
        const from = (tilePage - 1) * TILES_PER_PAGE;
        grid.innerHTML = platformList.slice(from, from + TILES_PER_PAGE).map(tileHTML).join('');
        markSelected();
        renderPager(tilesPager, {
            page: tilePage, pages: TILE_PAGES,
            onChange: (p) => { tilePage = p; renderTiles(true); },
        });
        if (scroll) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    // pindah ke halaman tile yang memuat platform ini (dipakai saat dibuka dari URL / chip di detail)
    const revealPlatform = (name) => {
        const i = platformList.findIndex((g) => g.name === name);
        if (i < 0) return;
        const p = Math.floor(i / TILES_PER_PAGE) + 1;
        if (p !== tilePage) { tilePage = p; renderTiles(false); }
    };

    let current = null;
    renderTiles(false);

    /*  Hasil per platform + search + pagination  */
    const section = $('#platformResults'), title = $('#resultsTitle'), status = $('#resultsStatus');
    const list = $('#platformShows'), sortSel = $('#sortSelect'), pager = $('#pager');
    const qForm = $('#platformSearch'), qInput = $('#platformQ'), qClear = $('#platformClear');
    let sorted = [], page = 1;

    const apply = () => {
        const q = qInput.value.trim().toLowerCase();
        let items = PLATFORMS.get(current) || [];
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
            : `<p class="results__empty">No shows on ${esc(current)} match “${esc(q)}”.</p>`;
        status.textContent = total
            ? `${(from + 1).toLocaleString()}–${Math.min(from + PER_PAGE, total).toLocaleString()} of ${total.toLocaleString()} show${total === 1 ? '' : 's'}`
            : '';
        renderPager(pager, {
            page, pages,
            onChange: (p) => { page = p; setParams({ page: p > 1 ? p : null }, 'push'); render(true); },
        });
        if (scroll) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const hidePlatform = () => {
        current = null; section.hidden = true; list.innerHTML = ''; document.title = 'Platform — ITV';
        qInput.value = ''; qClear.hidden = true;
        markSelected();
    };

    const showPlatform = (name, p = 1, scroll = true, q = qInput.value) => {
        if (!PLATFORMS.has(name)) return hidePlatform();
        current = name; page = p;
        qInput.value = q; qClear.hidden = !q;
        qInput.placeholder = `Search in ${name}...`;
        title.textContent = name;
        section.hidden = false;
        document.title = `${name} — ITV`;
        revealPlatform(name);
        markSelected();
        apply();
        render(scroll);
    };

    grid.addEventListener('click', (e) => {
        const tile = e.target.closest('.genre-tile');
        if (!tile) return;
        e.preventDefault();
        const name = tile.dataset.platform;
        setParams({ platform: name, page: null, q: null }, 'push');
        showPlatform(name, 1, true, '');
    });

    sortSel.addEventListener('change', () => {
        if (!current) return;
        setParams({ page: null });
        showPlatform(current, 1, false);
    });

    // search di dalam platform
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
        const p = sp.get('platform');
        p ? showPlatform(p, getPage(), false, sp.get('q') || '') : hidePlatform();
    });

    // buka langsung dari URL: /platform?platform=AMC&page=2&q=walking (dari chip di halaman detail)
    const sp = new URLSearchParams(location.search);
    const initial = sp.get('platform');
    if (initial) showPlatform(initial, getPage(), true, sp.get('q') || '');
})();