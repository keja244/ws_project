/* Browse tile + hasil per entitas (genre / platform).
   Tile datang dari SPARQL (window.ITV_GENRES / ITV_PLATFORMS); hasil series diambil per halaman
   dari /api/{genre|platform}/{slug}?q=&sort=&page=&per= (cari judul, urut, paginasi = SPARQL). */
(() => {
    'use strict';
    const { $, esc, cardHTML, getPage, setParams, normalize } = window.ITV;

    const ARROW_L = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';
    const ARROW_R = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

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

    /**
     * cfg = { base, api, tiles, initial, pathName, emptyText, ids: {grid, moreWrap, more, tilePager,
     *         results, title, status, list, sort, pager, form, q, clear} }
     */
    window.ITV.initBrowse = (cfg) => {
        const PER_PAGE = 21;
        const TILES_PER_PAGE = 12;
        const BASE = cfg.base, API = cfg.api;

        const tiles = Array.isArray(cfg.tiles) ? cfg.tiles : [];
        const BY_SLUG = new Map(tiles.map((t) => [t.slug, t]));
        const el = Object.fromEntries(Object.entries(cfg.ids).map(([k, id]) => [k, $('#' + id)]));

        let current = null, currentName = '';
        let page = 1, q = '', sort = 'rating';
        let tilePage = 1, showAll = false;
        let seq = 0, ctrl = null;

        /* ---------- Tile ---------- */
        const tileHTML = (g) => `
            <a class="genre-tile${g.slug === current ? ' is-selected' : ''}" href="${BASE}${encodeURIComponent(g.slug)}" data-slug="${esc(g.slug)}">
                ${g.cover ? `<img src="${esc(g.cover)}" alt="" loading="lazy" draggable="false" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}
                <span class="genre-tile__label">
                    <span class="genre-tile__name">${esc(g.name)}</span>
                    <span class="genre-tile__count">${Number(g.count).toLocaleString()} show${Number(g.count) === 1 ? '' : 's'}</span>
                </span>
            </a>`;

        if (!tiles.length) {
            el.grid.innerHTML = `<p class="results__empty">${esc(cfg.emptyText || 'No data available yet.')}</p>`;
            el.moreWrap.hidden = true;
            return;
        }

        const TILE_PAGES = Math.max(1, Math.ceil(tiles.length / TILES_PER_PAGE));
        const markSelected = () => el.grid.querySelectorAll('.genre-tile')
            .forEach((t) => t.classList.toggle('is-selected', t.dataset.slug === current));

        const renderTiles = (scroll) => {
            tilePage = Math.min(Math.max(tilePage, 1), TILE_PAGES);
            const from = (tilePage - 1) * TILES_PER_PAGE;
            const items = showAll ? tiles : tiles.slice(from, from + TILES_PER_PAGE);
            el.grid.innerHTML = items.map(tileHTML).join('');

            if (tiles.length <= TILES_PER_PAGE) {
                el.moreWrap.hidden = true;
                el.tilePager.hidden = true;
                return;
            }
            el.more.textContent = showAll ? 'Show less' : 'Show all';
            el.more.setAttribute('aria-expanded', String(showAll));

            if (showAll) {
                el.tilePager.hidden = true;
                el.tilePager.innerHTML = '';
            } else {
                renderPager(el.tilePager, {
                    page: tilePage, pages: TILE_PAGES,
                    onChange: (p) => { tilePage = p; renderTiles(true); },
                });
            }
            if (scroll) el.grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        el.more.addEventListener('click', () => {
            showAll = !showAll;
            if (!showAll) {
                const i = current ? tiles.findIndex((g) => g.slug === current) : -1;
                tilePage = i >= 0 ? Math.floor(i / TILES_PER_PAGE) + 1 : 1;
            }
            renderTiles(!showAll);
        });

        const revealTile = (slug) => {
            if (showAll) return;
            const i = tiles.findIndex((g) => g.slug === slug);
            if (i < 0) return;
            const p = Math.floor(i / TILES_PER_PAGE) + 1;
            if (p !== tilePage) { tilePage = p; renderTiles(false); }
        };

        renderTiles(false);

        /* ---------- Hasil: semuanya dari SPARQL ---------- */
        const showMessage = (text) => {
            el.list.innerHTML = `<p class="results__empty">${esc(text)}</p>`;
            el.status.textContent = '';
            renderPager(el.pager, { page: 1, pages: 1, onChange() {} });
        };

        const fetchPage = async () => {
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            const sp = new URLSearchParams({ page, per: PER_PAGE });
            if (q) sp.set('q', q);
            if (sort !== 'rating') sp.set('sort', sort);
            const res = await fetch(`${API}${encodeURIComponent(current)}?${sp}`, {
                signal: ctrl.signal, headers: { Accept: 'application/json' },
            });
            if (!res.ok) throw new Error(res.status);
            return res.json();
        };

        const load = async (scroll = false) => {
            const my = ++seq;
            el.status.textContent = 'Loading…';

            let d;
            try {
                d = await fetchPage();
            } catch (err) {
                if (err.name === 'AbortError' || my !== seq) return;
                console.error('[Browse]', err);
                return showMessage(`Couldn’t load ${currentName} shows right now. Please try again.`);
            }
            if (my !== seq) return;

            if (d.page !== page) { page = d.page; setParams({ page: page > 1 ? page : null }); }
            const total = d.total;
            const from = (page - 1) * PER_PAGE;

            el.list.innerHTML = total
                ? d.shows.map(normalize).map(cardHTML).join('')
                : `<p class="results__empty">${q ? `No ${esc(currentName)} shows match “${esc(q)}”.` : `No ${esc(currentName)} shows.`}</p>`;
            el.status.textContent = total
                ? `${(from + 1).toLocaleString()}–${Math.min(from + d.shows.length, total).toLocaleString()} of ${total.toLocaleString()} show${total === 1 ? '' : 's'}`
                : '';
            renderPager(el.pager, {
                page, pages: d.pages,
                onChange: (p) => { page = p; setParams({ page: p > 1 ? p : null }, 'push'); load(true); },
            });
            if (scroll) el.results.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        const hide = () => {
            seq++;
            if (ctrl) ctrl.abort();
            current = null; currentName = ''; q = ''; sort = 'rating'; page = 1;
            el.results.hidden = true; el.list.innerHTML = '';
            document.title = `${cfg.label} — ITV`;
            el.q.value = ''; el.clear.hidden = true; el.sort.value = 'rating';
            markSelected();
        };

        const show = (slug, { p = 1, scroll = true, query = '', order = 'rating' } = {}) => {
            const tile = BY_SLUG.get(slug);
            if (!tile) return hide();
            current = slug; currentName = tile.name; page = p; q = query; sort = order;
            el.q.value = q; el.clear.hidden = !q;
            el.q.placeholder = `Search in ${tile.name}...`;
            el.sort.value = sort;
            el.title.textContent = tile.name;
            el.results.hidden = false;
            document.title = `${tile.name} — ITV`;
            revealTile(slug);
            markSelected();
            load(scroll);
        };

        const readUrl = () => {
            const sp = new URLSearchParams(location.search);
            const s = sp.get('sort');
            return { p: getPage(), query: sp.get('q') || '', order: ['rating', 'votes', 'newest', 'seasons'].includes(s) ? s : 'rating' };
        };

        /* ---------- Event ---------- */
        el.grid.addEventListener('click', (e) => {
            const tile = e.target.closest('.genre-tile');
            if (!tile) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1) return;
            e.preventDefault();
            const slug = tile.dataset.slug;
            history.pushState(null, '', BASE + encodeURIComponent(slug));
            show(slug, { scroll: true });
        });

        el.sort.addEventListener('change', () => {
            if (!current) return;
            sort = el.sort.value; page = 1;
            setParams({ sort: sort === 'rating' ? null : sort, page: null });
            load(false);
        });

        let t;
        const runSearch = () => {
            if (!current) return;
            q = el.q.value.trim();
            el.clear.hidden = !el.q.value;
            page = 1;
            setParams({ q: q || null, page: null });
            load(false);
        };
        el.q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(runSearch, 250); });
        el.form.addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(t); runSearch(); });
        el.clear.addEventListener('click', () => { el.q.value = ''; runSearch(); el.q.focus(); });

        const pathSlug = () => {
            const m = location.pathname.match(new RegExp(`/${cfg.pathName}/([^/]+)/?$`));
            return m ? decodeURIComponent(m[1]) : null;
        };
        window.addEventListener('popstate', () => {
            const slug = pathSlug();
            slug ? show(slug, { ...readUrl(), scroll: false }) : hide();
        });

        if (cfg.initial) show(cfg.initial, { ...readUrl(), scroll: true });
    };
})();