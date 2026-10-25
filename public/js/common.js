/* Helper bersama semua halaman: data, normalisasi, kartu, kategori, pagination. */
window.ITV = (() => {
    'use strict';

    const $  = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => [...c.querySelectorAll(s)];
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

    /*  Data contoh*/
    const IMG = 'https://m.media-amazon.com/images/M/MV5BOWE4NTc3YmYtNmU2Mi00ZjhkLWE1MTItZmM1M2U1ODU3YjFlXkEyXkFqcGc@._V1_QL75_UY562_CR2,0,380,562_.jpg';
    const DEMO_GENRES = ['Crime', 'Drama', 'Comedy', 'Sci-Fi', 'Mystery', 'Fantasy'];
    const PLACEHOLDER = Array.from({ length: 48 }, (_, i) => ({
        id: `tt0903747-${i + 1}`,
        name: 'Breaking Bad',
        aggregateRating: 9.5 - i * 0.01,
        interactionCount: 2680071 - i * 1000,
        startDate: 2008, endDate: 2013, numberOfSeasons: 5,
        award: 'Won 16 Primetime Emmys. 172 wins & 269 nominations total',
        countryOfOrigin: i % 3 === 0 ? 'Japan' : 'United States',
        image: IMG,
        genre: `${DEMO_GENRES[i % DEMO_GENRES.length]}, Thriller`,
        actor: 'Bryan Cranston, Aaron Paul, Anna Gunn',
        description: "A chemistry teacher diagnosed with inoperable lung cancer turns to manufacturing and selling methamphetamine with a former student to secure his family's future.",
    }));
    const USING_PLACEHOLDER = !(Array.isArray(window.ITV_SHOWS) && window.ITV_SHOWS.length);

    const split = (v) => String(v || '').split(',').map((x) => x.trim()).filter(Boolean);

    const normalize = (r) => {
        const start = parseInt(r.startDate, 10) || 0;
        const end = r.endDate === null || r.endDate === '' || r.endDate === undefined ? null : parseInt(r.endDate, 10);
        const wins = ((r.award || '').match(/(\d+)\s+wins/i) || [])[1];
        const genres = split(r.genre);
        return {
            id: r.id || r.name,
            name: r.name || 'Untitled',
            rating: parseFloat(r.aggregateRating) || 0,
            votes: parseInt(r.interactionCount, 10) || 0,
            start,
            years: !start ? '' : end === null ? `${start} - now` : end === start ? `${start}` : `${start} - ${end}`,
            seasons: parseInt(r.numberOfSeasons, 10) || 0,
            wins: wins ? parseInt(wins, 10) : 0,
            country: r.countryOfOrigin || '',
            image: r.image || '',
            genres,
            genre: genres.join(', '),
            cast: split(r.actor).join(', '),
            platforms: Array.isArray(r.platform) ? r.platform : split(r.platform),
            desc: r.description || '',
            roles: Array.isArray(r.roles) ? r.roles : [],
        };
    };

    const SHOWS = (USING_PLACEHOLDER ? PLACEHOLDER : window.ITV_SHOWS).map(normalize);

    const sortBy = (list, fn) => [...list].sort(fn);
    const byRating = (a, b) => b.rating - a.rating || b.votes - a.votes;
    const byVotes = (a, b) => b.votes - a.votes || b.rating - a.rating;

    /*  Kategori */
    const ASIA = ['Japan', 'South Korea', 'Korea', 'China', 'India', 'Thailand', 'Taiwan', 'Hong Kong', 'Philippines', 'Indonesia', 'Singapore', 'Malaysia', 'Vietnam'];

    // "Recommended by Us": isi ID IMDb (mis. 'tt0903747') untuk pilihan manual, maksimal 10.
    // Kalau dikosongkan, dipilih acak dari show ber-rating >= 8 (berubah tiap hari).
    const RECOMMENDED_IDS = ['tt0903747', 'tt0944947', 'tt4574334', 'tt7366338', 'tt2861424', 'tt1475582', 'tt7366338', 'tt0108778', 'tt0106179', 'tt0111161'];
    const REC_LIMIT = 10, TOP_LIMIT = 10;

    const seeded = (seed) => {
        let a = seed >>> 0;
        return () => {
            a = (a + 0x6D2B79F5) >>> 0;
            let t = a;
            t = Math.imul(t ^ (t >>> 15), t | 1);
            t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    };
    const recommended = () => {
        const byId = new Map(SHOWS.map((s) => [s.id, s]));
        // new Set(...) = buang ID yang tertulis dobel; yang tidak ada di data otomatis dilewati
        const manual = [...new Set(RECOMMENDED_IDS)].map((id) => byId.get(id)).filter(Boolean);
        if (manual.length >= REC_LIMIT) return manual.slice(0, REC_LIMIT);
        const d = new Date();
        const rnd = seeded(d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate());
        let pool = SHOWS.filter((s) => s.rating >= 8 && s.image && !manual.includes(s));
        if (!pool.length) pool = SHOWS.filter((s) => !manual.includes(s));
        for (let i = pool.length - 1; i > 0; i--) {
            const j = Math.floor(rnd() * (i + 1));
            [pool[i], pool[j]] = [pool[j], pool[i]];
        }
        // sisa slot diisi acak (berubah tiap hari) supaya selalu terisi 10
        return [...manual, ...pool].slice(0, REC_LIMIT);
    };

    const CATEGORIES = [
        { slug: 'recommended',  title: 'Recommended by Us',   fixed: true, get: recommended },
        { slug: 'top-picks',    title: 'Top Picks',           fixed: true, get: () => sortBy(SHOWS, byRating).slice(0, TOP_LIMIT) },
        { slug: 'popular',      title: 'Most Popular',        get: () => sortBy(SHOWS, byVotes) },
        { slug: 'long-running', title: 'Long-Running Series', get: () => sortBy(SHOWS.filter((s) => s.seasons >= 2), (a, b) => b.seasons - a.seasons || byRating(a, b)) },
        { slug: 'awards',       title: 'Award Winning',       get: () => sortBy(SHOWS.filter((s) => s.wins > 0), (a, b) => b.wins - a.wins || byRating(a, b)) },
        { slug: 'asian',        title: 'Top Asian',           get: () => sortBy(SHOWS.filter((s) => ASIA.some((c) => s.country.includes(c))), byRating) },
    ];

    /*  Kartu  */
    const STAR = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="m12 2.5 2.9 6.1 6.6.9-4.8 4.6 1.2 6.6L12 17.4 6.1 20.7l1.2-6.6L2.5 9.5l6.6-.9L12 2.5Z"/></svg>';

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

    /*  klik / Enter di kartu -> halaman detail  */
    const state = { dragged: false };
    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const openCard = (card) => {
        if (state.dragged) { state.dragged = false; return; }
        location.href = DETAIL_URL + encodeURIComponent(card.dataset.id);
    };
    document.addEventListener('click', (e) => { const c = e.target.closest('.card'); if (c) openCard(c); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.classList?.contains('card')) openCard(e.target); });

    /*  URL: ?page=2 dst  */
    const getPage = () => Math.max(1, parseInt(new URLSearchParams(location.search).get('page'), 10) || 1);
    const setParams = (obj, mode = 'replace') => {
        const u = new URL(location.href);
        Object.entries(obj).forEach(([k, v]) => (v === null || v === undefined || v === '') ? u.searchParams.delete(k) : u.searchParams.set(k, v));
        history[mode === 'push' ? 'pushState' : 'replaceState'](null, '', u);
    };

    /*  Pagination  */
    const CH_L = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';
    const CH_R = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

    const pageList = (cur, last) => {
        const set = new Set([1, last, cur - 1, cur, cur + 1]);
        if (cur <= 3) [2, 3, 4].forEach((n) => set.add(n));
        if (cur >= last - 2) [last - 3, last - 2, last - 1].forEach((n) => set.add(n));
        const nums = [...set].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b);
        const out = [];
        nums.forEach((n, i) => { if (i && n - nums[i - 1] > 1) out.push('gap'); out.push(n); });
        return out;
    };

    const renderPager = (el, { page, pages, onChange }) => {
        if (pages <= 1) { el.hidden = true; el.innerHTML = ''; return; }
        el.hidden = false;
        el.innerHTML =
            `<button type="button" class="pg" data-p="${page - 1}" ${page === 1 ? 'disabled' : ''} aria-label="Previous page">${CH_L}</button>` +
            pageList(page, pages).map((n) => n === 'gap'
                ? '<span class="pg__gap">…</span>'
                : `<button type="button" class="pg ${n === page ? 'is-current' : ''}" data-p="${n}" ${n === page ? 'aria-current="page"' : ''}>${n}</button>`).join('') +
            `<button type="button" class="pg" data-p="${page + 1}" ${page === pages ? 'disabled' : ''} aria-label="Next page">${CH_R}</button>`;
        el.onclick = (e) => {
            const b = e.target.closest('button[data-p]');
            if (b && !b.disabled) onChange(parseInt(b.dataset.p, 10));
        };
    };

    return { $, $$, esc, SHOWS, USING_PLACEHOLDER, sortBy, byRating, byVotes, CATEGORIES, STAR, cardHTML, state, getPage, setParams, renderPager };
})();