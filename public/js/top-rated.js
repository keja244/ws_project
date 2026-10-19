(() => {
    'use strict';
    const { $, esc, SHOWS, byRating, STAR, renderPager, getPage, setParams } = window.ITV;

    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const PER_PAGE = 12;
    const RANKED = [...SHOWS].sort(byRating);
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

    const list = $('#rankList'), status = $('#rankStatus'), pager = $('#rankPager');
    let page = getPage();

    const render = (scroll) => {
        const total = RANKED.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        page = Math.min(Math.max(page, 1), pages);
        const from = (page - 1) * PER_PAGE;

        list.innerHTML = RANKED.slice(from, from + PER_PAGE).map((s, i) => cardHTML(s, from + i + 1)).join('');
        status.textContent = total ? `Showing ${from + 1}–${Math.min(from + PER_PAGE, total)} of ${total.toLocaleString()}` : '';
        renderPager(pager, {
            page, pages,
            onChange: (p) => { page = p; setParams({ page: p > 1 ? p : null }, 'push'); render(true); },
        });
        if (scroll) window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    window.addEventListener('popstate', () => { page = getPage(); render(false); });
    render(false);
})();