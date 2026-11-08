(() => {
    'use strict';

    const { $, esc, STAR, renderPager } = window.ITV;

    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const BASE_URL = '/top-rated';
    const INIT = window.ITV_TOP_RATED || { rank: null, page: 1, per: 12 };
    const PER_PAGE = INIT.per || 12;
    const sep = '<span class="sep">|</span>';

    const num = (v) => { const n = parseInt(v, 10); return Number.isFinite(n) ? n : 0; };

    const yearsOf = (s) => {
        const a = num(String(s.startDate || '').slice(0, 4));
        const b = s.endDate ? num(String(s.endDate).slice(0, 4)) : 0;
        if (!a) return '';
        if (!s.endDate) return `${a} - now`;
        return b && b !== a ? `${a} - ${b}` : `${a}`;
    };

    const cardHTML = (s) => {
        const seasons = num(s.numberOfSeasons);
        const votes = num(s.interactionCount);
        const years = yearsOf(s);
        const target = INIT.rank && s.rank === INIT.rank ? ' is-target' : '';
        return `
        <a class="rank-card${target}" id="rank-card-${s.rank}"
           href="${DETAIL_URL}${encodeURIComponent(s.id)}">
            <span class="rank-card__no">${s.rank}</span>
            <div class="rank-card__poster">
                ${s.image
                    ? `<img src="${esc(s.image)}" alt="Poster of ${esc(s.name)}" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentNode.innerHTML='<div class=rank-card__fallback>${esc(s.name)}</div>'">`
                    : `<div class="rank-card__fallback">${esc(s.name)}</div>`}
            </div>
            <div class="rank-card__body">
                <h2 class="rank-card__title">${esc(s.name)}</h2>
                <p class="rank-card__meta">${esc(years)}${seasons ? `${sep}${seasons} Season${seasons > 1 ? 's' : ''}` : ''}${s.genre ? `${sep}${esc(s.genre)}` : ''}</p>
                <p class="rank-card__rating">${STAR}${Number(s.aggregateRating).toFixed(1)}/10${votes ? `${sep}${votes.toLocaleString()} votes` : ''}</p>
                ${s.description ? `<p class="rank-card__desc">${esc(s.description)}</p>` : ''}
            </div>
        </a>`;
    };

    const list = $('#rankList');
    const status = $('#rankStatus');
    const pager = $('#rankPager');

    let page = INIT.page || 1;
    let highlight = INIT.rank;      // hanya disorot/di-scroll pada pemuatan pertama

    const urlFor = (p) => (p > 1 ? `${BASE_URL}?page=${p}` : BASE_URL);

    const load = async (scroll = false) => {
        list.setAttribute('aria-busy', 'true');
        try {
            const res = await fetch(`/api/top-rated?page=${page}&per=${PER_PAGE}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            const d = await res.json();

            page = d.page;
            list.innerHTML = d.shows.map(cardHTML).join('');

            const from = (d.page - 1) * PER_PAGE;
            status.textContent = d.total
                ? `Showing ${from + 1}–${Math.min(from + d.shows.length, d.total)} of ${d.total.toLocaleString()}`
                : '';

            renderPager(pager, {
                page: d.page,
                pages: d.pages,
                onChange: (p) => {
                    page = p;
                    highlight = null;
                    history.pushState({ page: p }, '', urlFor(p));
                    load(true);
                },
            });

            if (scroll) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else if (highlight) {
                const el = document.getElementById(`rank-card-${highlight}`);
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } catch (err) {
            console.error('[TopRated]', err);
            list.innerHTML = '<p class="reviews__empty">Top Rated could not be loaded right now.</p>';
        } finally {
            list.removeAttribute('aria-busy');
        }
    };

    window.addEventListener('popstate', () => {
        const p = new URLSearchParams(window.location.search);
        page = Math.max(1, parseInt(p.get('page'), 10) || 1);
        highlight = null;
        load(false);
    });

    load(false);
})();