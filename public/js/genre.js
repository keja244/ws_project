(() => {
    'use strict';
    const { $, esc, SHOWS, byRating, cardHTML, renderPager, getPage, setParams } = window.ITV;

    const PER_PAGE = 21;                                   
    const TILES_SHOWN = 12;                                 
    const SORTERS = {
        rating: byRating,
        votes: (a, b) => b.votes - a.votes,
        newest: (a, b) => b.start - a.start || byRating(a, b),
        seasons: (a, b) => b.seasons - a.seasons || byRating(a, b),
    };

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

    const tileHTML = (g, i) => `
        <a class="genre-tile${i >= TILES_SHOWN ? ' is-extra' : ''}" href="?genre=${encodeURIComponent(g.name)}" data-genre="${esc(g.name)}">
            ${g.cover ? `<img src="${esc(g.cover)}" alt="" loading="lazy" draggable="false" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}
            <span class="genre-tile__label">
                <span class="genre-tile__name">${esc(g.name)}</span>
                <span class="genre-tile__count">${g.count.toLocaleString()} shows</span>
            </span>
        </a>`;

    const grid = $('#genreGrid'), moreWrap = $('#genreMoreWrap'), moreBtn = $('#genreMore');
    grid.innerHTML = genreList.map(tileHTML).join('');

    /*  See more / Show less untuk daftar genre  */
    if (genreList.length > TILES_SHOWN) {
        grid.classList.add('is-collapsed');
        moreBtn.addEventListener('click', () => {
            const collapsed = grid.classList.toggle('is-collapsed');
            moreBtn.textContent = collapsed ? 'See more' : 'Show less';
            moreBtn.setAttribute('aria-expanded', String(!collapsed));
        });
    } else {
        moreWrap.hidden = true;
    }
    const revealGenre = (name) => {                       
        const i = genreList.findIndex((g) => g.name === name);
        if (i >= TILES_SHOWN && grid.classList.contains('is-collapsed')) moreBtn.click();
    };

    /*  Hasil per genre + search + pagination  */
    const section = $('#genreResults'), title = $('#resultsTitle'), status = $('#resultsStatus');
    const list = $('#genreShows'), sortSel = $('#sortSelect'), pager = $('#pager');
    const qForm = $('#genreSearch'), qInput = $('#genreQ'), qClear = $('#genreClear');
    let current = null, sorted = [], page = 1;

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
        grid.querySelectorAll('.genre-tile').forEach((t) => t.classList.toggle('is-selected', t.dataset.genre === name));
        revealGenre(name);
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