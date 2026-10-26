(() => {
    'use strict';

    const { $, esc, SHOWS, STAR, renderPager, getPage, setParams } = window.ITV;

    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const PER_PAGE = 12;
    const sep = '<span class="sep">|</span>';

    // ---------- Perhitungan rank sendiri (berdasarkan data TTL) ----------
    // Jumlah vote = schema:ratingCount di TTL
    const votesOf = (s) => Number(s.votes ?? s.ratingCount ?? s.rating_count ?? 0) || 0;
    const ratingOf = (s) => Number(s.rating) || 0;

    // Hanya series yang punya rating yang ikut dihitung
    const RATED = SHOWS.filter(s => ratingOf(s) > 0);

    // C = rata-rata rating seluruh series
    const C = RATED.reduce((sum, s) => sum + ratingOf(s), 0) / (RATED.length || 1);

    // m = minimum vote (persentil ke-75 dari jumlah vote)
    const sortedVotes = RATED.map(votesOf).sort((a, b) => a - b);
    const M = sortedVotes[Math.floor(sortedVotes.length * 0.75)] || 1;

    // Weighted rating (Bayesian)
    const score = (s) => {
        const v = votesOf(s);
        return (v / (v + M)) * ratingOf(s) + (M / (v + M)) * C;
    };

    // Urutan: skor WR desc -> rating desc -> vote desc -> nama A-Z
    const compareRank = (a, b) =>
        score(b) - score(a) ||
        ratingOf(b) - ratingOf(a) ||
        votesOf(b) - votesOf(a) ||
        String(a.name).localeCompare(String(b.name));

    const RANKED = [...RATED].sort(compareRank);

    // Simpan rank hasil hitung kita agar bisa dipakai tempat lain
    RANKED.forEach((s, i) => { s.ourRank = i + 1; s.score = score(s); });
    // ---------------------------------------------------------------------

    const params = new URLSearchParams(window.location.search);
    const targetId = params.get('id');

    const targetIndex = targetId
        ? RANKED.findIndex(s => String(s.id) === String(targetId))
        : -1;

    const cardHTML = (s, rank) => `
        <a class="rank-card" id="rank-card-${rank}"
           href="${DETAIL_URL}${encodeURIComponent(s.id)}">
            <span class="rank-card__no">${rank}</span>
            <div class="rank-card__poster">
                ${s.image
                    ? `<img src="${esc(s.image)}" alt="Poster of ${esc(s.name)}" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentNode.innerHTML='<div class=rank-card__fallback>${esc(s.name)}</div>'">`
                    : `<div class="rank-card__fallback">${esc(s.name)}</div>`}
            </div>
            <div class="rank-card__body">
                <h2 class="rank-card__title">${esc(s.name)}</h2>
                <p class="rank-card__meta">${esc(s.years)}${s.seasons ? `${sep}${s.seasons} Season${s.seasons > 1 ? 's' : ''}` : ''}${s.genre ? `${sep}${esc(s.genre)}` : ''}</p>
                <p class="rank-card__rating">${STAR}${s.rating.toFixed(1)}/10${votesOf(s) ? `${sep}${votesOf(s).toLocaleString()} votes` : ''}</p>
                ${s.desc ? `<p class="rank-card__desc">${esc(s.desc)}</p>` : ''}
            </div>
        </a>`;

    const list = $('#rankList');
    const status = $('#rankStatus');
    const pager = $('#rankPager');

    let page = targetIndex >= 0
        ? Math.floor(targetIndex / PER_PAGE) + 1
        : getPage();

    const render = (scroll = false) => {
        const total = RANKED.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));

        page = Math.min(Math.max(page, 1), pages);

        const from = (page - 1) * PER_PAGE;

        list.innerHTML = RANKED
            .slice(from, from + PER_PAGE)
            .map((s, i) => cardHTML(s, from + i + 1))
            .join('');

        status.textContent = total
            ? `Showing ${from + 1}–${Math.min(from + PER_PAGE, total)} of ${total.toLocaleString()}`
            : '';

        renderPager(pager, {
            page,
            pages,
            onChange: (p) => {
                page = p;
                setParams({ page: p > 1 ? p : null }, 'push');
                render(true);
            }
        });

        if (scroll) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else if (targetIndex >= 0) {
            const targetCard = document.getElementById(
                `rank-card-${targetIndex + 1}`
            );

            if (targetCard) {
                targetCard.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        }
    };

    window.addEventListener('popstate', () => {
        const currentParams = new URLSearchParams(window.location.search);
        page = Math.max(1, parseInt(currentParams.get('page'), 10) || 1);
        render(false);
    });

    render(false);
})();