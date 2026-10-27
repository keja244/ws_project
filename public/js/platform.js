/* Halaman Platform: /platform?platform=AMC&page=2&q=breaking
   Struktur sama dengan genre.js. */
(() => {
    'use strict';
    const { $, esc, SHOWS, USING_PLACEHOLDER, byRating, cardHTML, getPage, setParams } = window.ITV;

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
        <a class="genre-tile${g.name === current ? ' is-selected' : ''}" href="?platform=${encodeURIComponent(g.name)}" data-platform="${esc(g.name)}">
            ${g.cover ? `<img src="${esc(g.cover)}" alt="" loading="lazy" draggable="false" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}
            <span class="genre-tile__label">
                <span class="genre-tile__name">${esc(g.name)}</span>
                <span class="genre-tile__count">${g.count.toLocaleString()} show${g.count === 1 ? '' : 's'}</span>
            </span>
        </a>`;

    const grid = $('#platformGrid'), moreWrap = $('#platformMoreWrap'), moreBtn = $('#platformMore'), tilesPager = $('#platformTilesPager');

    if (!platformList.length) {
        grid.innerHTML = '<p class="results__empty">No platform data available yet.</p>';
        moreWrap.hidden = true;
        return;
    }

    /*  Pagination + Show all / Show less untuk daftar tile platform */
    const TILE_PAGES = Math.ceil(platformList.length / TILES_PER_PAGE);
    let current = null;
    let tilePage = 1, showAll = false;

    // pager tile: style sama dengan pagination review di halaman detail 
    const renderTilePager = () => renderPager(tilesPager, {
        page: tilePage, pages: TILE_PAGES,
        onChange: (p) => { tilePage = p; renderTiles(true); },
    });

    const markSelected = () => grid.querySelectorAll('.genre-tile')
        .forEach((t) => t.classList.toggle('is-selected', t.dataset.platform === current));

    const renderTiles = (scroll) => {
        tilePage = Math.min(Math.max(tilePage, 1), TILE_PAGES);
        const from = (tilePage - 1) * TILES_PER_PAGE;
        const items = showAll ? platformList : platformList.slice(from, from + TILES_PER_PAGE);
        grid.innerHTML = items.map(tileHTML).join('');

        // platform sedikit -> tidak perlu pagination maupun tombol
        if (platformList.length <= TILES_PER_PAGE) {
            moreWrap.hidden = true;
            tilesPager.hidden = true;
            return;
        }

        moreBtn.textContent = showAll ? 'Show less' : 'Show all';
        moreBtn.setAttribute('aria-expanded', String(showAll));

        if (showAll) {
            tilesPager.hidden = true;
            tilesPager.innerHTML = '';
        } else {
            renderTilePager();
        }
        if (scroll) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    moreBtn.addEventListener('click', () => {
        showAll = !showAll;
        if (!showAll) {
            // kembali ke mode halaman: tampilkan halaman yang memuat platform terpilih (kalau ada)
            const i = current ? platformList.findIndex((g) => g.name === current) : -1;
            tilePage = i >= 0 ? Math.floor(i / TILES_PER_PAGE) + 1 : 1;
        }
        renderTiles(!showAll);
    });

    // pindah ke halaman tile yang memuat platform ini (dipakai saat dibuka dari URL / chip di detail)
    const revealPlatform = (name) => {
        if (showAll) return;
        const i = platformList.findIndex((g) => g.name === name);
        if (i < 0) return;
        const p = Math.floor(i / TILES_PER_PAGE) + 1;
        if (p !== tilePage) { tilePage = p; renderTiles(false); }
    };

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