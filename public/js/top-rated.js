(() => {
    'use strict';

    const BREAKING_BAD = {
        name: 'Breaking Bad',
        aggregateRating: 9.5,
        interactionCount: 2680071,
        startDate: 2008,
        endDate: 2013,
        numberOfSeasons: 5,
        genre: 'Crime, Drama, Thriller',
        description: "A chemistry teacher diagnosed with inoperable lung cancer turns to manufacturing and selling methamphetamine with a former student to secure his family's future.",
        image: 'https://m.media-amazon.com/images/M/MV5BOWE4NTc3YmYtNmU2Mi00ZjhkLWE1MTItZmM1M2U1ODU3YjFlXkEyXkFqcGc@._V1_QL75_UY562_CR2,0,380,562_.jpg',
    };
    const PLACEHOLDER = Array.from({ length: 50 }, (_, i) => ({ ...BREAKING_BAD, id: `tt0903747-${i + 1}` }));

    const $ = (s) => document.querySelector(s);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const PAGE = 20;

    const normalize = (r) => {
        const start = parseInt(r.startDate, 10) || 0;
        const end = r.endDate === null || r.endDate === '' || r.endDate === undefined ? null : parseInt(r.endDate, 10);
        return {
            id: r.id || r.name,
            name: r.name || 'Untitled',
            rating: parseFloat(r.aggregateRating) || 0,
            votes: parseInt(r.interactionCount, 10) || 0,
            years: end === null ? `${start} - now` : end === start ? `${start}` : `${start} - ${end}`,
            seasons: parseInt(r.numberOfSeasons, 10) || 0,
            genre: String(r.genre || '').split(',').map((g) => g.trim()).filter(Boolean).join(', '),
            desc: r.description || '',
            image: r.image || '',
        };
    };

    const useReal = Array.isArray(window.ITV_SHOWS) && window.ITV_SHOWS.length;
    const SHOWS = (useReal ? window.ITV_SHOWS : PLACEHOLDER)
        .map(normalize)
        .sort((a, b) => b.rating - a.rating || b.votes - a.votes);

    const STAR = '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="m12 2.5 2.9 6.1 6.6.9-4.8 4.6 1.2 6.6L12 17.4 6.1 20.7l1.2-6.6L2.5 9.5l6.6-.9L12 2.5Z"/></svg>';
    const sep = '<span class="sep">|</span>';

    const cardHTML = (s, rank) => `
        <a class="rank-card" href="${DETAIL_URL}${encodeURIComponent(s.id)}">
            <span class="rank-card__no">${rank}</span>
            <div class="rank-card__poster">
                ${s.image
                    ? `<img src="${esc(s.image)}" alt="Poster of ${esc(s.name)}" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentNode.innerHTML='<div class=rank-card__fallback>${esc(s.name)}</div>'">`
                    : `<div class="rank-card__fallback">${esc(s.name)}</div>`}
            </div>
            <div class="rank-card__body">
                <h2 class="rank-card__title">${esc(s.name)}</h2>
                <p class="rank-card__meta">${esc(s.years)}${s.seasons ? `${sep}${s.seasons} Season${s.seasons > 1 ? 's' : ''}` : ''}${s.genre ? `${sep}${esc(s.genre)}` : ''}</p>
                <p class="rank-card__rating">${STAR}${s.rating.toFixed(1)}/10</p>
                ${s.desc ? `<p class="rank-card__desc">${esc(s.desc)}</p>` : ''}
            </div>
        </a>`;

    const list = $('#rankList'), more = $('#rankMore'), status = $('#rankStatus'), btn = $('#rankBtn');
    let shown = 0;

    const renderMore = () => {
        const next = SHOWS.slice(shown, shown + PAGE);
        list.insertAdjacentHTML('beforeend', next.map((s, i) => cardHTML(s, shown + i + 1)).join(''));
        shown += next.length;
        status.textContent = `Showing ${shown} of ${SHOWS.length}`;
        more.hidden = shown >= SHOWS.length;
    };
    btn.addEventListener('click', renderMore);
    renderMore();

    if ('IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && shown < SHOWS.length) renderMore();
        }, { rootMargin: '400px' }).observe(more);
    }

})();