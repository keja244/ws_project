/* Halaman Platform: /platform/amc?page=2&q=breaking
   Struktur sama dengan genre.js. Tile + keanggotaan platform datang dari graf (ex:Platform, ex:platform). */
(() => {
    'use strict';
    const { $, esc, byRating, cardHTML, getPage, setParams, normalize } = window.ITV;
    if (!normalize) {
        console.error('[ITV] common.js belum mengekspor normalize. Tambahkan `normalize` pada baris return di common.js.');
        return;
    }

    /* URL & endpoint (bisa ditimpa lewat window.ITV_PLATFORM_URL / ITV_PLATFORM_API) */
    const BASE = window.ITV_PLATFORM_URL || '/platform/';
    const API = window.ITV_PLATFORM_API || '/api/platform/';

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
    // pencarian judul: tanpa huruf besar/kecil dan tanpa aksen
    const fold = (s) => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

    /* State */
    let current = null, currentName = '';                   // platform terpilih (slug + nama)
    let all = [], sorted = [], page = 1;                    // series platform terpilih
    let loading = false, seq = 0, ctrl = null;              // pemuat data (seq: abaikan respons basi)
    let tilePage = 1, showAll = false;                      // daftar tile platform
    const cache = new Map();                                // slug -> series (sudah dinormalisasi)

    /* Tile platform: datang dari server (query SPARQL ke entitas ex:Platform), paling banyak show di depan */
    const platformList = Array.isArray(window.ITV_PLATFORMS) ? window.ITV_PLATFORMS : [];
    const BY_SLUG = new Map(platformList.map((p) => [p.slug, p]));

    const tileHTML = (g) => `
        <a class="genre-tile${g.slug === current ? ' is-selected' : ''}" href="${BASE}${encodeURIComponent(g.slug)}" data-platform="${esc(g.slug)}">
            ${g.cover ? `<img src="${esc(g.cover)}" alt="" loading="lazy" draggable="false" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}
            <span class="genre-tile__label">
                <span class="genre-tile__name">${esc(g.name)}</span>
                <span class="genre-tile__count">${Number(g.count).toLocaleString()} show${Number(g.count) === 1 ? '' : 's'}</span>
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
            const i = current ? platformList.findIndex((g) => g.slug === current) : -1;
            tilePage = i >= 0 ? Math.floor(i / TILES_PER_PAGE) + 1 : 1;
        }
        renderTiles(!showAll);
    });

    // pindah ke halaman tile yang memuat platform ini (dipakai saat dibuka dari URL / chip di detail)
    const revealPlatform = (slug) => {
        if (showAll) return;
        const i = platformList.findIndex((g) => g.slug === slug);
        if (i < 0) return;
        const p = Math.floor(i / TILES_PER_PAGE) + 1;
        if (p !== tilePage) { tilePage = p; renderTiles(false); }
    };

    renderTiles(false);

    /*  Data per platform: GET /api/platform/{slug} -> query SPARQL berbasis IRI platform  */
    const loadShows = async (slug) => {
        if (cache.has(slug)) return cache.get(slug);
        if (ctrl) ctrl.abort();                             // klik tile lain: batalkan permintaan sebelumnya
        ctrl = new AbortController();
        const res = await fetch(API + encodeURIComponent(slug), { signal: ctrl.signal, headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(res.status);
        const d = await res.json();
        const shows = (d.shows || []).map(normalize);
        cache.set(slug, shows);
        return shows;
    };

    /*  Hasil per platform + search judul + pagination  */
    const section = $('#platformResults'), title = $('#resultsTitle'), status = $('#resultsStatus');
    const list = $('#platformShows'), sortSel = $('#sortSelect'), pager = $('#pager');
    const qForm = $('#platformSearch'), qInput = $('#platformQ'), qClear = $('#platformClear');

    const apply = () => {
        const q = fold(qInput.value.trim());
        let items = all;
        if (q) items = items.filter((s) => fold(s.name).includes(q));
        sorted = [...items].sort(SORTERS[sortSel.value]);
    };

    const showMessage = (text) => {
        list.innerHTML = `<p class="results__empty">${esc(text)}</p>`;
        status.textContent = '';
        renderPager(pager, { page: 1, pages: 1, onChange() {} });
    };

    const render = (scroll) => {
        const total = sorted.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        page = Math.min(Math.max(page, 1), pages);
        const from = (page - 1) * PER_PAGE;
        const q = qInput.value.trim();

        list.innerHTML = total
            ? sorted.slice(from, from + PER_PAGE).map(cardHTML).join('')
            : `<p class="results__empty">${q ? `No shows on ${esc(currentName)} match “${esc(q)}”.` : `No shows on ${esc(currentName)}.`}</p>`;
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
        seq++; loading = false;                             // abaikan respons yang masih berjalan
        if (ctrl) ctrl.abort();
        current = null; currentName = ''; all = []; sorted = [];
        section.hidden = true; list.innerHTML = ''; document.title = 'Platform — ITV';
        qInput.value = ''; qClear.hidden = true;
        markSelected();
    };

    const showPlatform = async (slug, p = 1, scroll = true, q = qInput.value) => {
        const tile = BY_SLUG.get(slug);
        if (!tile) return hidePlatform();
        current = slug; currentName = tile.name; page = p;
        qInput.value = q; qClear.hidden = !q;
        qInput.placeholder = `Search in ${tile.name}...`;
        title.textContent = tile.name;
        section.hidden = false;
        document.title = `${tile.name} — ITV`;
        revealPlatform(slug);
        markSelected();

        const my = ++seq;
        if (cache.has(slug)) {                              // sudah pernah dibuka: instan
            loading = false; all = cache.get(slug);
            apply(); return render(scroll);
        }

        loading = true; all = []; sorted = [];
        showMessage('Loading…');
        if (scroll) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        try {
            const shows = await loadShows(slug);
            if (my !== seq) return;                         // sudah pindah platform lain
            all = shows;
        } catch (err) {
            if (err.name === 'AbortError' || my !== seq) return;
            console.error('[Platform]', err);
            loading = false;
            return showMessage(`Couldn’t load ${tile.name} shows right now. Please try again.`);
        }
        loading = false;
        apply(); render(false);
    };

    grid.addEventListener('click', (e) => {
        const tile = e.target.closest('.genre-tile');
        if (!tile) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1) return;   // buka di tab baru: biarkan
        e.preventDefault();
        const slug = tile.dataset.platform;
        history.pushState(null, '', BASE + encodeURIComponent(slug));
        showPlatform(slug, 1, true, '');
    });

    sortSel.addEventListener('change', () => {
        if (!current || loading) return;
        page = 1;
        setParams({ page: null });
        apply(); render(false);
    });

    // search judul di dalam platform
    let t;
    const runSearch = () => {
        if (!current || loading) return;
        const q = qInput.value.trim();
        qClear.hidden = !qInput.value;
        setParams({ q: q || null, page: null });
        page = 1; apply(); render(false);
    };
    qInput.addEventListener('input', () => { clearTimeout(t); t = setTimeout(runSearch, 150); });
    qForm.addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(t); runSearch(); });
    qClear.addEventListener('click', () => { qInput.value = ''; runSearch(); qInput.focus(); });

    // slug platform dibaca dari path: /platform/{slug}
    const pathSlug = () => {
        const m = location.pathname.match(/\/platform\/([^/]+)\/?$/);
        return m ? decodeURIComponent(m[1]) : null;
    };

    window.addEventListener('popstate', () => {
        const slug = pathSlug();
        const sp = new URLSearchParams(location.search);
        slug ? showPlatform(slug, getPage(), false, sp.get('q') || '') : hidePlatform();
    });

    // buka langsung dari URL: /platform/amc?page=2&q=walking (juga dari chip di halaman detail)
    const initial = window.ITV_PLATFORM;
    if (initial) showPlatform(initial, getPage(), true, new URLSearchParams(location.search).get('q') || '');
})();