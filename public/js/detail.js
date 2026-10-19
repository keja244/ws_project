(() => {
    'use strict';

    const PLACEHOLDER = {
        id: 'tt0903747',
        name: 'Breaking Bad',
        aggregateRating: 9.5,
        interactionCount: 2680071,
        startDate: 2008,
        endDate: 2013,
        numberOfSeasons: 5,
        duration: '49 min',
        contentRating: 'TV-MA',
        genre: 'Crime, Drama, Thriller',
        author: 'Vince Gilligan',
        actor: 'Bryan Cranston, Aaron Paul, Anna Gunn',
        description: "A chemistry teacher diagnosed with inoperable lung cancer turns to manufacturing and selling methamphetamine with a former student to secure his family's future.",
        award: 'Won 16 Primetime Emmys. 172 wins & 269 nominations total',
        countryOfOrigin: 'United States',
        image: 'https://m.media-amazon.com/images/M/MV5BOWE4NTc3YmYtNmU2Mi00ZjhkLWE1MTItZmM1M2U1ODU3YjFlXkEyXkFqcGc@._V1_QL75_UY562_CR2,0,380,562_.jpg',
    };

    const r = window.ITV_SHOW || PLACEHOLDER;

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const list = (s) => String(s || '').split(',').map((x) => x.trim()).filter(Boolean);

    const start = parseInt(r.startDate, 10) || '';
    const end = r.endDate === null || r.endDate === '' || r.endDate === undefined ? null : parseInt(r.endDate, 10);
    const years = end === null ? `${start} - now` : end === start ? `${start}` : `${start} - ${end}`;
    const seasons = parseInt(r.numberOfSeasons, 10) || 0;
    const minutes = parseInt(r.duration, 10) || 0;
    const votes = (parseInt(r.interactionCount, 10) || 0).toLocaleString('en-US');

    // link ke halaman IMDb (controller kirim r.uri; kalau tidak ada, bentuk dari id)
    const GENRE_URL = window.ITV_GENRE_URL || '/genre';
    const imdbUrl = r.uri || `https://www.imdb.com/title/${r.id || 'tt0903747'}/`;

    const award = r.award || '';
    const emmy = award.match(/Won\s+(\d+)\s+Primetime Emmys?/i);
    const wins = award.match(/(\d+)\s+wins/i);
    const noms = award.match(/(\d+)\s+nominations/i);
    const awardLines = [
        emmy && `${emmy[1]} Primetime Emmy Wins`,
        (wins || noms) && `${wins ? wins[1] : 0} wins | ${noms ? noms[1] : 0} nominations total`,
    ].filter(Boolean);
    if (!awardLines.length && award) awardLines.push(award);

    const STAR = '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="m12 2.5 2.9 6.1 6.6.9-4.8 4.6 1.2 6.6L12 17.4 6.1 20.7l1.2-6.6L2.5 9.5l6.6-.9L12 2.5Z"/></svg>';
    const EXT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M8 7h9v9"/></svg>';
    const sep = '<span class="sep">|</span>';

    document.title = `${r.name} — ITV`;

    document.getElementById('detail').innerHTML = `
        <div class="detail__top">
            <div class="detail__poster">
                ${r.image
                    ? `<img src="${esc(r.image)}" alt="Poster of ${esc(r.name)}" referrerpolicy="no-referrer" onerror="this.parentNode.innerHTML='<div class=fallback>${esc(r.name)}</div>'">`
                    : `<div class="fallback">${esc(r.name)}</div>`}
            </div>

            <div class="detail__main">
                <div class="detail__titlerow">
                    <h1 class="detail__title">${esc(r.name)}</h1>
                    <a class="imdb-btn" href="${esc(imdbUrl)}" target="_blank" rel="noopener noreferrer">View on IMDb ${EXT}</a>
                </div>
                <p class="detail__meta">${esc(years)}${r.countryOfOrigin ? `${sep}${esc(r.countryOfOrigin)}` : ''}</p>
                <p class="detail__rating">${STAR}${(parseFloat(r.aggregateRating) || 0).toFixed(1)}/10</p>
                <p class="detail__votes">${votes} votes<small>Based on IMDb users rating</small></p>

                <hr class="line">

                <section>
                    <h2>Genres</h2>
                    <div class="chips">${list(r.genre).map((g) => `<a class="chip" href="${GENRE_URL}?genre=${encodeURIComponent(g)}">${esc(g)}</a>`).join('')}</div>
                </section>

                <section class="info">
                    <h2>Series Info</h2>
                    <ul>
                        ${seasons ? `<li>${seasons} Season${seasons > 1 ? 's' : ''}</li>` : ''}
                        ${minutes ? `<li>${minutes} Minute / episode</li>` : ''}
                        ${r.contentRating ? `<li>${esc(r.contentRating)}</li>` : ''}
                    </ul>
                </section>
            </div>
        </div>

        <hr class="line">

        <div class="detail__body">
            <section><h2>Overview</h2><p>${esc(r.description)}</p></section>
            ${r.author ? `<section><h2>Created by</h2><p>${esc(r.author)}</p></section>` : ''}
            ${r.actor ? `<section class="cast"><h2>Main Cast</h2><p>${list(r.actor).map(esc).join(sep)}</p></section>` : ''}
            ${awardLines.length ? `<div class="awards"><h2>Awards &amp; Recognition</h2>${awardLines.map((l) => `<p>${esc(l)}</p>`).join('')}</div>` : ''}
        </div>`;
})();