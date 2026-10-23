(() => {
    'use strict';

    /* Data contoh */
    const PLACEHOLDER = {
        id: 'tt0903747',
        imdbId: 'tt0903747',
        name: 'Breaking Bad',
        slogan: 'Change the equation.',
        rank: 1,
        status: 'Ended',
        aggregateRating: 9.5,
        bestRating: 10,
        interactionCount: 2680071,
        startDate: 2008,
        endDate: 2013,
        numberOfSeasons: 5,
        numberOfEpisodes: 62,
        duration: 49,
        contentRating: 'TV-MA',
        genre: 'Crime, Drama, Thriller',
        author: 'Vince Gilligan',
        actor: 'Bryan Cranston, Aaron Paul, Anna Gunn, Dean Norris, RJ Mitte',
        description: "A chemistry teacher diagnosed with inoperable lung cancer turns to manufacturing and selling methamphetamine with a former student to secure his family's future.",
        award: 'Won 16 Primetime Emmys. 172 wins & 269 nominations total',
        countryOfOrigin: 'United States',
        inLanguage: 'English, Spanish',
        spokenLanguage: ['English', 'German', 'Spanish'],
        platform: ['AMC'],
        productionCompany: ['Gran Via Productions', 'High Bridge Productions', 'Sony Pictures Television'],
        keywords: ['cancer', 'dark comedy', 'drug dealer', 'drugs', 'new mexico'],
        tmdbId: 1396, tvdbId: 81189, wikidataId: 'Q1079',
        links: ['https://www.themoviedb.org/tv/1396', 'https://www.sonypictures.com/tv/breakingbad'],
        image: 'https://m.media-amazon.com/images/M/MV5BOWE4NTc3YmYtNmU2Mi00ZjhkLWE1MTItZmM1M2U1ODU3YjFlXkEyXkFqcGc@._V1_QL75_UY562_CR2,0,380,562_.jpg',
    };

    const r = window.ITV_SHOW || PLACEHOLDER;
    const ITV = window.ITV || {};

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    // menerima array ATAU string "A, B, C"
    const list = (v) => (Array.isArray(v) ? v : String(v || '').split(',')).map((x) => String(x).trim()).filter(Boolean);
    const num = (v) => { const n = parseInt(v, 10); return Number.isFinite(n) ? n : 0; };
    const fmt = (n) => n.toLocaleString('en-US');

    // nama orang (cast / creator) -> chip yang membuka /person?name=...
    const personChips = (names) => `<div class="chips chips--sm">${names.map((n) =>
        `<a class="chip chip--tag" href="${PERSON_URL}?name=${encodeURIComponent(n)}">${esc(n)}</a>`).join('')}</div>`;

    /*  URL halaman lain */
    const GENRE_URL = window.ITV_GENRE_URL || '/genre';
    const PLATFORM_URL = window.ITV_PLATFORM_URL || '/platform';
    const KEYWORD_URL = window.ITV_KEYWORD_URL || '/keyword';
    const PERSON_URL = window.ITV_PERSON_URL || '/person';

    /*  Tahun & status  */
    const status = r.status || '';
    const start = num(r.startDate) || '';
    const hasEnd = !(r.endDate === null || r.endDate === '' || r.endDate === undefined);
    const end = hasEnd ? num(r.endDate) : null;
    const finished = /^(ended|canceled|cancelled)$/i.test(status);
    const years = !start ? ''
        : end === null ? (finished ? `${start}` : `${start} - now`)
        : end === start ? `${start}` : `${start} - ${end}`;

    const seasons = num(r.numberOfSeasons);
    const episodes = num(r.numberOfEpisodes);
    const minutes = num(r.duration);
    const votes = fmt(num(r.interactionCount));
    const rank = num(r.rank);
    const best = parseFloat(r.bestRating) || 10;
    const rating = parseFloat(r.aggregateRating) || 0;

    // Ended / Canceled = merah, Returning Series = hijau, selain itu warna biasa
    const statusClass = /returning/i.test(status) ? 'st-live' : finished ? 'st-end' : '';

    /*  Link eksternal (IMDb sudah ada tombolnya di atas)  */
    const imdbUrl = r.uri || `https://www.imdb.com/title/${r.imdbId || r.id}/`;

    const rawLinks = list(r.links);
    const find = (re) => rawLinks.find((u) => re.test(u));
    const tmdbUrl = find(/themoviedb\.org/i) || (r.tmdbId ? `https://www.themoviedb.org/tv/${r.tmdbId}` : '');
    const wikiUrl = find(/wikidata\.org/i) || (r.wikidataId ? `https://www.wikidata.org/wiki/${r.wikidataId}` : '');
    const tvdbUrl = r.tvdbId ? `https://thetvdb.com/dereferrer/series/${r.tvdbId}` : '';
    const official = rawLinks.filter((u) => !/imdb\.com|themoviedb\.org|wikidata\.org/i.test(u));

    // [nama, url, kelas warna]
    const extLinks = [
        ['TMDB', tmdbUrl, 'link-btn--tmdb'],
        ['TheTVDB', tvdbUrl, 'link-btn--tvdb'],
        ['Wikidata', wikiUrl, 'link-btn--wiki'],
        ...official.map((u, i) => [official.length > 1 ? `Official Site ${i + 1}` : 'Official Site', u, 'link-btn--official']),
    ].filter(([, u]) => u);

    /*  Series Info  */
    const spoken = list(r.spokenLanguage);
    const infoRows = [
        ['Status', status && `<span class="st ${statusClass}">${esc(status)}</span>`],
        ['Episodes', episodes && `${fmt(episodes)} Episode${episodes > 1 ? 's' : ''}`],
        ['Content Rating', r.contentRating && esc(r.contentRating)],
        ['Spoken Languages', spoken.length && spoken.map(esc).join(', ')],
        ['Seasons', seasons && `${seasons} Season${seasons > 1 ? 's' : ''}`],
        ['Runtime', minutes && `${minutes} Minute${minutes > 1 ? 's' : ''} / episode`],
    ].filter(([, v]) => v);

    /*  Awards & Recognition  */
    const award = r.award || '';
    const winsTotal = num((award.match(/(\d+)\s+wins?/i) || [])[1]);
    const nomsTotal = num((award.match(/(\d+)\s+nominations?/i) || [])[1]);
    const AWARD_DEFS = [
        ['Primetime Emmy', 'Primetime Emmys?'],
        ['Oscar', 'Oscars?'],
        ['Golden Globe', 'Golden Globes?'],
        ['BAFTA', 'BAFTA(?: Awards?)?'],
    ];
    const featured = AWARD_DEFS.map(([label, pat]) => {
        const w = award.match(new RegExp(`Won\\s+(\\d+)\\s+${pat}`, 'i'));
        const n = award.match(new RegExp(`Nominated for\\s+(\\d+)\\s+${pat}`, 'i'));
        return (w || n) ? { label, won: w ? num(w[1]) : 0, nom: n ? num(n[1]) : 0 } : null;
    }).filter(Boolean);

    const sumAwards = winsTotal + nomsTotal;
    const winRate = sumAwards ? Math.round((winsTotal / sumAwards) * 100) : 0;
    const hasStats = sumAwards > 0;
    // chip award 
    const featuredChips = () => featured.map((f) => {
        const won = f.won > 0;
        const n = won ? f.won : f.nom;
        return `<span class="achip${won ? '' : ' achip--nom'}"><b>${fmt(n)}</b>${esc(f.label)}${n > 1 ? 's' : ''} ${won ? 'won' : 'nominated'}</span>`;
    });

    /*  Icons  */
    const STAR = '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="m12 2.5 2.9 6.1 6.6.9-4.8 4.6 1.2 6.6L12 17.4 6.1 20.7l1.2-6.6L2.5 9.5l6.6-.9L12 2.5Z"/></svg>';
    const EXT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M8 7h9v9"/></svg>';
    const TROPHY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>';
    const ARROW_L = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';
    const ARROW_R = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
    const sep = '<span class="sep">|</span>';

    document.title = `${r.name} — ITV`;

    const genres = list(r.genre);
    const cast = list(r.actor);
    const creators = list(r.author);
    const companies = list(r.productionCompany);
    const keywords = list(r.keywords);
    const platforms = list(r.platform);

    // baris meta di header
    const metaParts = [years, ...list(r.countryOfOrigin).slice(0, 1)].filter(Boolean);

    // Similar recommendation: 12 series yang sudah dihitung server 
    const similar = ITV.SHOWS && !ITV.USING_PLACEHOLDER ? ITV.SHOWS : [];

    document.getElementById('detail').innerHTML = `
        <div class="detail__top">
            <div class="detail__poster">
                ${r.image
                    ? `<img src="${esc(r.image)}" alt="Poster of ${esc(r.name)}" referrerpolicy="no-referrer" onerror="this.parentNode.innerHTML='<div class=fallback>${esc(r.name)}</div>'">`
                    : `<div class="fallback">${esc(r.name)}</div>`}
            </div>

            <aside class="detail__side">
                ${infoRows.length ? `<section class="info">
                    <h2>Series Info</h2>
                    <dl>${infoRows.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${v}</dd></div>`).join('')}</dl>
                </section>` : ''}
            </aside>

            <div class="detail__main">
                <div class="detail__titlerow">
                    <h1 class="detail__title">${esc(r.name)}</h1>
                    <a class="imdb-btn" href="${esc(imdbUrl)}" target="_blank" rel="noopener noreferrer">View on IMDb ${EXT}</a>
                </div>
                ${r.slogan ? `<p class="detail__slogan">“${esc(r.slogan)}”</p>` : ''}
                ${metaParts.length ? `<p class="detail__meta">${metaParts.map(esc).join(sep)}</p>` : ''}

                <div class="detail__scorerow">
                    <div>
                        <p class="detail__rating">${STAR}${rating.toFixed(1)}/${best}</p>
                        <p class="detail__votes">${votes} votes<small>Based on IMDb users rating</small></p>
                    </div>
                    ${rank ? `<div class="rankbox"><span>IMDb Rank</span><strong>#${fmt(rank)}</strong></div>` : ''}
                </div>

                <hr class="line">

                ${genres.length ? `<section>
                    <h2>Genres</h2>
                    <div class="chips">${genres.map((g) => `<a class="chip" href="${GENRE_URL}?genre=${encodeURIComponent(g)}">${esc(g)}</a>`).join('')}</div>
                </section>` : ''}

                <div class="prose">
                    ${r.description ? `<section><h2>Overview</h2><p>${esc(r.description)}</p></section>` : ''}
                    ${creators.length ? `<section><h2>Created by</h2>${personChips(creators)}</section>` : ''}
                    ${cast.length ? `<section><h2>Main Cast</h2>${personChips(cast)}</section>` : ''}
                    ${companies.length ? `<section><h2>Production Companies</h2><p>${companies.map(esc).join(sep)}</p></section>` : ''}
                    ${keywords.length ? `<section><h2>Keywords</h2>
                        <div class="chips chips--sm">${keywords.map((k) => `<a class="chip chip--tag" href="${KEYWORD_URL}?k=${encodeURIComponent(k)}">${esc(k)}</a>`).join('')}</div>
                    </section>` : ''}
                    ${platforms.length ? `<section class="platforms">
                        <h2>Platform</h2>
                        <div class="chips">${platforms.map((p) => `<a class="chip" href="${PLATFORM_URL}?platform=${encodeURIComponent(p)}">${esc(p)}</a>`).join('')}</div>
                    </section>` : ''}
                </div>
            </div>
        </div>

        <hr class="line">

        ${similar.length ? `<section class="similar">
            <div class="row" id="similar">
                <div class="row__head">
                    <h2>Similar Recommendations</h2>
                    <div class="row__arrows">
                        <button type="button" class="arrow" data-dir="-1" aria-label="Previous">${ARROW_L}</button>
                        <button type="button" class="arrow" data-dir="1" aria-label="Next">${ARROW_R}</button>
                    </div>
                </div>
                <div class="row__track">${similar.map((s) => ITV.cardHTML(s)).join('')}</div>
            </div>
        </section>` : ''}

        <div class="detail__body">
            ${(hasStats || featured.length) ? `<section class="awards">
                <div class="awards__head">
                    <span class="awards__icon">${TROPHY}</span>
                    <h2>Awards &amp; Recognition</h2>
                </div>
                ${hasStats ? `<div class="astats">
                    <div class="astat"><strong>${fmt(winsTotal)}</strong><span><i class="dot dot--win"></i>Wins</span></div>
                    <div class="astat"><strong>${fmt(nomsTotal)}</strong><span><i class="dot dot--nom"></i>Nominations</span></div>
                    <div class="astat"><strong>${winRate}%</strong><span>Win rate</span></div>
                </div>
                <div class="abar" role="img" aria-label="${winRate}% of awards were wins"><i style="width:${winRate}%"></i></div>` : ''}
                ${featured.length ? `<div class="amajor">
                    <h3>Major awards</h3>
                    <div class="achips">${featuredChips().join('')}</div>
                </div>` : ''}
            </section>` : award ? `<section class="awards">
                <div class="awards__head">
                    <span class="awards__icon">${TROPHY}</span>
                    <h2>Awards &amp; Recognition</h2>
                </div>
                <p class="awards__src">${esc(award)}</p>
            </section>` : ''}

            ${extLinks.length ? `<section class="links"><h2>External Links</h2>
                <div class="links__row">${extLinks.map(([n, u, c]) => `<a class="link-btn ${c}" href="${esc(u)}" target="_blank" rel="noopener noreferrer">${esc(n)} ${EXT}</a>`).join('')}</div>
            </section>` : ''}

            <p class="detail__ids">${[
                r.imdbId && `IMDb ID: ${esc(r.imdbId)}`,
                r.tmdbId && `TMDB ID: ${esc(r.tmdbId)}`,
                r.tvdbId && `TVDB ID: ${esc(r.tvdbId)}`,
                r.wikidataId && `Wikidata: ${esc(r.wikidataId)}`,
            ].filter(Boolean).join(sep)}</p>
        </div>`;

    /*  Carousel Similar  */
    const row = document.getElementById('similar');
    if (row) {
        const track = row.querySelector('.row__track');
        const [prev, next] = row.querySelectorAll('.arrow');

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
            down = true; ITV.state.dragged = false; startX = e.clientX; startLeft = track.scrollLeft;
        });
        window.addEventListener('pointermove', (e) => {
            if (!down) return;
            const dx = e.clientX - startX;
            if (Math.abs(dx) > 5) { ITV.state.dragged = true; track.classList.add('is-dragging'); }
            track.scrollLeft = startLeft - dx;
        });
        window.addEventListener('pointerup', () => { down = false; track.classList.remove('is-dragging'); });
    }
})();