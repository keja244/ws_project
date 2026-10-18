(() => {
    'use strict';

    const BREAKING_BAD = {
        name: 'Breaking Bad',
        aggregateRating: 9.5,
        interactionCount: 2680071,
        startDate: 2008,
        endDate: 2013,
        numberOfSeasons: 5,
        award: 'Won 16 Primetime Emmys. 172 wins & 269 nominations total',
        countryOfOrigin: 'United States',
        image: 'https://m.media-amazon.com/images/M/MV5BOWE4NTc3YmYtNmU2Mi00ZjhkLWE1MTItZmM1M2U1ODU3YjFlXkEyXkFqcGc@._V1_QL75_UY562_CR2,0,380,562_.jpg',
    };
    const PLACEHOLDER = Array.from({ length: 20 }, (_, i) => ({ ...BREAKING_BAD, id: `tt0903747-${i + 1}` }));

    const USING_PLACEHOLDER = !(Array.isArray(window.ITV_SHOWS) && window.ITV_SHOWS.length);

    const $  = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => [...c.querySelectorAll(s)];
    let dragged = false; // supaya habis drag carousel tidak langsung buka detail
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const ASIA = ['Japan', 'South Korea', 'Korea', 'China', 'India', 'Thailand', 'Taiwan', 'Hong Kong', 'Philippines', 'Indonesia', 'Singapore', 'Malaysia', 'Vietnam'];

    const normalize = (r) => {
        const start = parseInt(r.startDate, 10) || 0;
        const end = r.endDate === null || r.endDate === '' || r.endDate === undefined ? null : parseInt(r.endDate, 10);
        const wins = ((r.award || '').match(/(\d+)\s+wins/i) || [])[1];
        return {
            id: r.id || r.name,
            name: r.name || 'Untitled',
            rating: parseFloat(r.aggregateRating) || 0,
            votes: parseInt(r.interactionCount, 10) || 0,
            start,
            years: end === null ? `${start} - now` : end === start ? `${start}` : `${start} - ${end}`,
            seasons: parseInt(r.numberOfSeasons, 10) || 0,
            wins: wins ? parseInt(wins, 10) : 0,
            country: r.countryOfOrigin || '',
            image: r.image || '',
        };
    };

    const SHOWS = (USING_PLACEHOLDER ? PLACEHOLDER : window.ITV_SHOWS).map(normalize);

    const sortBy = (list, fn) => [...list].sort(fn);
    const byRating = (a, b) => b.rating - a.rating || b.votes - a.votes;
    const byVotes = (a, b) => b.votes - a.votes;

    const STAR = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="m12 2.5 2.9 6.1 6.6.9-4.8 4.6 1.2 6.6L12 17.4 6.1 20.7l1.2-6.6L2.5 9.5l6.6-.9L12 2.5Z"/></svg>';
    const ARROW_L = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';
    const ARROW_R = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

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

    const rowHTML = (r) => `
        <section class="row reveal" id="${r.id}">
            <div class="row__head">
                <h2>${esc(r.title)}</h2>
                <div class="row__arrows">
                    <button type="button" class="arrow" data-dir="-1" aria-label="Previous">${ARROW_L}</button>
                    <button type="button" class="arrow" data-dir="1" aria-label="Next">${ARROW_R}</button>
                </div>
            </div>
            <div class="row__track">${r.list.map(cardHTML).join('')}</div>
        </section>`;

    const pick = (filterFn, sorter) => sortBy(USING_PLACEHOLDER ? SHOWS : SHOWS.filter(filterFn), sorter);

    const rowsTop = [
        { id: 'top-picks',    title: 'Top Picks',           list: sortBy(SHOWS, byRating).slice(0, 5) },
        { id: 'popular',      title: 'Most Popular',        list: sortBy(SHOWS, byVotes) },
        { id: 'long-running', title: 'Long-Running Series', list: pick((s) => s.seasons >= 2, (a, b) => b.seasons - a.seasons) },
    ];
    const rowsBottom = [
        { id: 'awards', title: 'Award Winning', list: pick((s) => s.wins > 0, (a, b) => b.wins - a.wins) },
        { id: 'asian',  title: 'Top Asian',     list: pick((s) => ASIA.some((c) => s.country.includes(c)), byRating) },
    ];
  
    const renderRows = (target, rows) => { $(target).innerHTML = rows.filter((r) => r.list.length).map(rowHTML).join(''); };
    renderRows('#rowsTop', rowsTop);

    const initRow = (row) => {
        const track = $('.row__track', row);
        const [prev, next] = $$('.arrow', row);

        const update = () => {
            const max = track.scrollWidth - track.clientWidth - 2;
            row.classList.toggle('no-overflow', max <= 0);
            prev.disabled = track.scrollLeft <= 2;
            next.disabled = track.scrollLeft >= max;
        };
        const go = (dir) => track.scrollBy({ left: dir * track.clientWidth * 0.92, behavior: 'smooth' });
        prev.addEventListener('click', () => go(-1));
        next.addEventListener('click', () => go(1));
        track.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();

        let down = false, startX = 0, startLeft = 0;
        track.addEventListener('pointerdown', (e) => {
            if (e.pointerType !== 'mouse') return;
            down = true; dragged = false; startX = e.clientX; startLeft = track.scrollLeft;
        });
        window.addEventListener('pointermove', (e) => {
            if (!down) return;
            const dx = e.clientX - startX;
            if (Math.abs(dx) > 5) { dragged = true; track.classList.add('is-dragging'); }
            track.scrollLeft = startLeft - dx;
        });
        window.addEventListener('pointerup', () => { down = false; track.classList.remove('is-dragging'); });
    };
    $$('.row').forEach(initRow);

    const input = $('#searchInput'), results = $('#results'), status = $('#searchStatus');

    const runSearch = () => {
        const q = input.value.trim().toLowerCase();
        if (!q) { results.innerHTML = ''; status.textContent = ''; return; }
        const list = sortBy(SHOWS.filter((s) => (/^\d{4}$/.test(q) ? String(s.start) === q : s.name.toLowerCase().includes(q))), byVotes);
        results.innerHTML = list.length
            ? list.slice(0, 24).map(cardHTML).join('')
            : '<p class="results__empty">No shows found. Try another title or year.</p>';
        status.textContent = `${list.length} show${list.length === 1 ? '' : 's'} found`;
    };
    let t;
    input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(runSearch, 200); });

    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const openDetail = (card) => { if (!dragged) location.href = DETAIL_URL + encodeURIComponent(card.dataset.id); };
    document.addEventListener('click', (e) => { const c = e.target.closest('.card'); if (c) openDetail(c); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.classList?.contains('card')) openDetail(e.target); });

    const posters = [...new Set(sortBy(SHOWS, byRating).map((s) => s.image).filter(Boolean))];
    const tiles = $$('.collage__tile');
    if (posters.length) {
        let cursor = 0;
        const swap = (tile) => {
            const url = posters[cursor++ % posters.length];
            const front = $$('img', tile).find((i) => i.classList.contains('is-front'));
            if (front && front.src === url) return;
            const img = new Image();
            img.alt = ''; img.referrerPolicy = 'no-referrer'; img.draggable = false;
            img.onload = () => {
                tile.appendChild(img);
                requestAnimationFrame(() => {
                    img.classList.add('is-front');
                    if (front) { front.classList.remove('is-front'); setTimeout(() => front.remove(), 1200); }
                });
            };
            img.src = url;
        };

        if (posters.length > 1) {
            let k = 0;
            setInterval(() => swap(tiles[k++ % tiles.length]), 3500);
        }
    }

    const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { threshold: 0.12 });
    $$('.reveal').forEach((el) => io.observe(el));
})();