(() => {
    'use strict';

    /* ---------- Data (sama seperti home.js: pakai window.ITV_SHOWS, kalau kosong pakai contoh) ---------- */
    const IMG = 'https://m.media-amazon.com/images/M/MV5BOWE4NTc3YmYtNmU2Mi00ZjhkLWE1MTItZmM1M2U1ODU3YjFlXkEyXkFqcGc@._V1_QL75_UY562_CR2,0,380,562_.jpg';
    const DEMO_GENRES = ['Action', 'Adventure', 'Animation', 'Comedy', 'Crime', 'Documentary', 'Drama', 'Fantasy', 'Horror', 'Mystery', 'Romance', 'Sci-Fi'];
    const PLACEHOLDER = DEMO_GENRES.flatMap((g, gi) => Array.from({ length: 6 }, (_, i) => ({
        id: `demo-${gi}-${i}`, name: 'Breaking Bad', aggregateRating: 9.5 - i * 0.1, interactionCount: 2680071 - i * 1000,
        startDate: 2008 + i, endDate: 2013 + i, numberOfSeasons: 5, countryOfOrigin: 'United States',
        image: IMG, genre: `${g}, Drama`, actor: 'Bryan Cranston, Aaron Paul',
    })));
    const USING_PLACEHOLDER = !(Array.isArray(window.ITV_SHOWS) && window.ITV_SHOWS.length);

    const $  = (s, c = document) => c.querySelector(s);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

    const normalize = (r) => {
        const start = parseInt(r.startDate, 10) || 0;
        const end = r.endDate === null || r.endDate === '' || r.endDate === undefined ? null : parseInt(r.endDate, 10);
        return {
            id: r.id || r.name,
            name: r.name || 'Untitled',
            rating: parseFloat(r.aggregateRating) || 0,
            votes: parseInt(r.interactionCount, 10) || 0,
            start,
            years: end === null ? `${start} - now` : end === start ? `${start}` : `${start} - ${end}`,
            seasons: parseInt(r.numberOfSeasons, 10) || 0,
            image: r.image || '',
            genres: String(r.genre || '').split(',').map((g) => g.trim()).filter(Boolean),
        };
    };
    const SHOWS = (USING_PLACEHOLDER ? PLACEHOLDER : window.ITV_SHOWS).map(normalize);

    /* ---------- Kelompokkan per genre ---------- */
    const byRating = (a, b) => b.rating - a.rating || b.votes - a.votes;
    const SORTERS = {
        rating: byRating,
        votes: (a, b) => b.votes - a.votes,
        newest: (a, b) => b.start - a.start || byRating(a, b),
        seasons: (a, b) => b.seasons - a.seasons || byRating(a, b),
    };

    const GENRES = new Map(); // nama -> [shows]
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

    /* ---------- Render ---------- */
    const STAR = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="m12 2.5 2.9 6.1 6.6.9-4.8 4.6 1.2 6.6L12 17.4 6.1 20.7l1.2-6.6L2.5 9.5l6.6-.9L12 2.5Z"/></svg>';

    const cardHTML = (s) => `
        <article class="card" tabindex="0" data-id="${esc(s.id)}">
            <div class="card__poster">
                <div class="card__fallback">${esc(s.name)}</div>
                ${s.image ? `<img src="${esc(s.image)}" alt="Poster of ${esc(s.name)}" loading="lazy" draggable="false" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}
            </div>
            <h3 class="card__title">${esc(s.name)}</h3>
            <p class="card__years">${esc(s.years)}</p>
            <p class="card__rating">${STAR}${s.rating.toFixed(1)}</p>
        </article>`;

    const tileHTML = (g) => `
        <a class="genre-tile" href="?genre=${encodeURIComponent(g.name)}" data-genre="${esc(g.name)}">
            ${g.cover ? `<img src="${esc(g.cover)}" alt="" loading="lazy" draggable="false" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}
            <span class="genre-tile__label">
                <span class="genre-tile__name">${esc(g.name)}</span>
                <span class="genre-tile__count">${g.count.toLocaleString()} shows</span>
            </span>
        </a>`;

    const grid = $('#genreGrid');
    grid.innerHTML = genreList.map(tileHTML).join('');

    /* ---------- Hasil per genre ---------- */
    const section = $('#genreResults'), title = $('#resultsTitle'), status = $('#resultsStatus');
    const list = $('#genreShows'), sortSel = $('#sortSelect'), moreBtn = $('#moreBtn');
    const PAGE = 24;
    let current = null, sorted = [], shown = 0;

    const renderMore = () => {
        const next = sorted.slice(shown, shown + PAGE);
        list.insertAdjacentHTML('beforeend', next.map(cardHTML).join(''));
        shown += next.length;
        moreBtn.hidden = shown >= sorted.length;
        status.textContent = `Showing ${shown.toLocaleString()} of ${sorted.length.toLocaleString()} show${sorted.length === 1 ? '' : 's'}`;
    };

    const showGenre = (name, { scroll = true } = {}) => {
        const items = GENRES.get(name);
        if (!items) return hideGenre();
        current = name;
        sorted = [...items].sort(SORTERS[sortSel.value]);
        shown = 0; list.innerHTML = '';
        title.textContent = name;
        section.hidden = false;
        document.title = `${name} — ITV`;
        grid.querySelectorAll('.genre-tile').forEach((t) => t.classList.toggle('is-selected', t.dataset.genre === name));
        renderMore();
        if (scroll) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const hideGenre = () => {
        current = null; section.hidden = true; list.innerHTML = ''; document.title = 'Genre — ITV';
        grid.querySelectorAll('.genre-tile').forEach((t) => t.classList.remove('is-selected'));
    };

    grid.addEventListener('click', (e) => {
        const tile = e.target.closest('.genre-tile');
        if (!tile) return;
        e.preventDefault();
        const name = tile.dataset.genre;
        history.pushState({ genre: name }, '', `?genre=${encodeURIComponent(name)}`);
        showGenre(name);
    });
    sortSel.addEventListener('change', () => current && showGenre(current, { scroll: false }));
    moreBtn.addEventListener('click', renderMore);

    // tombol back/forward browser
    window.addEventListener('popstate', () => {
        const g = new URLSearchParams(location.search).get('genre');
        g ? showGenre(g, { scroll: false }) : hideGenre();
    });

    // buka langsung dari URL: /genre?genre=Romance  (atau window.ITV_GENRE dari backend)
    const initial = new URLSearchParams(location.search).get('genre') || window.ITV_GENRE;
    if (initial) showGenre(initial, { scroll: false });

    /* ---------- Klik kartu → halaman detail ---------- */
    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const openDetail = (card) => { location.href = DETAIL_URL + encodeURIComponent(card.dataset.id); };
    document.addEventListener('click', (e) => { const c = e.target.closest('.card'); if (c) openDetail(c); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.classList?.contains('card')) openDetail(e.target); });
})();