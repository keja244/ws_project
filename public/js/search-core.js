/* Mesin pencarian & filter (tanpa DOM). Dipakai halaman /search dan chatbot.
   Dimuat SETELAH common.js (common.js membuat window.ITV, file ini menambah ITV.engine).

    Parameter pencarian (P):
        q        teks pencarian
        in       cakupan teks: all | title | genre | year | cast
        genres   [..]  semua genre harus cocok (AND)
        from,to  rentang tahun tayang perdana
        rating   rating minimum
        seasons  jumlah season minimum        smax  jumlah season maksimum
        platform [..]  salah satu cocok (OR)  country [..]  salah satu cocok (OR)
        awards   hanya yang pernah menang penghargaan
        sort     relevance | rating | votes | newest | oldest | seasons

   URL: /search?q=bryan&in=cast&genre=Drama|Crime&from=1990&to=1999&rating=8&platform=AMC&sort=rating */
(function (root) {
    'use strict';
    const ITV = root.ITV || (root.ITV = {});

    /* Normalisasi teks  */
    const norm = (s) => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const clean = (s) => norm(s).replace(/&/g, ' and ').replace(/[^a-z0-9+ ]+/g, ' ').replace(/\s+/g, ' ').trim();
    const splitList = (v) => String(v || '').split(',').map((x) => x.trim()).filter(Boolean);

    const SCOPES = [['all', 'All'], ['title', 'Title'], ['genre', 'Genre'], ['year', 'Year'], ['cast', 'Cast']];
    const SORTS = [['relevance', 'Best match'], ['rating', 'Top rated'], ['votes', 'Most popular'], ['newest', 'Newest'], ['oldest', 'Oldest'], ['seasons', 'Most seasons']];
    const SCOPE_KEYS = SCOPES.map((s) => s[0]);
    const SORT_KEYS = SORTS.map((s) => s[0]);
    const scopeLabel = (k) => (SCOPES.find((s) => s[0] === k) || SCOPES[0])[1];

    /*  Parameter <-> URL  */
    const emptyParams = () => ({
        q: '', in: 'all', genres: [], from: null, to: null, rating: null,
        seasons: null, smax: null, platform: [], country: [], awards: false, sort: '',
    });
    const clone = (P) => JSON.parse(JSON.stringify(P));

    const paramsFromSearch = (search) => {
        const sp = new URLSearchParams(search);
        const list = (k) => (sp.get(k) || '').split('|').map((x) => x.trim()).filter(Boolean);
        const num = (k) => { const n = parseFloat(sp.get(k)); return Number.isFinite(n) ? n : null; };
        const P = emptyParams();
        P.q = (sp.get('q') || '').trim();
        P.in = SCOPE_KEYS.includes(sp.get('in')) ? sp.get('in') : 'all';
        P.genres = list('genre');
        P.from = num('from'); P.to = num('to');
        P.rating = num('rating'); P.seasons = num('seasons'); P.smax = num('smax');
        P.platform = list('platform'); P.country = list('country');
        P.awards = sp.get('awards') === '1';
        P.sort = SORT_KEYS.includes(sp.get('sort')) ? sp.get('sort') : '';
        return P;
    };

    const paramsToQuery = (P, extra = {}) => {
        const sp = new URLSearchParams();
        if (P.q) sp.set('q', P.q);
        if (P.q && P.in !== 'all') sp.set('in', P.in);
        if (P.genres.length) sp.set('genre', P.genres.join('|'));
        if (P.from != null) sp.set('from', P.from);
        if (P.to != null) sp.set('to', P.to);
        if (P.rating != null) sp.set('rating', P.rating);
        if (P.seasons != null) sp.set('seasons', P.seasons);
        if (P.smax != null) sp.set('smax', P.smax);
        if (P.platform.length) sp.set('platform', P.platform.join('|'));
        if (P.country.length) sp.set('country', P.country.join('|'));
        if (P.awards) sp.set('awards', '1');
        if (P.sort) sp.set('sort', P.sort);
        Object.entries(extra).forEach(([k, v]) => { if (v != null && v !== '') sp.set(k, v); });
        return sp.toString();
    };

    const countFilters = (P) => [
        P.genres.length > 0, P.from != null || P.to != null, P.rating != null,
        P.seasons != null || P.smax != null, P.platform.length > 0, P.country.length > 0, P.awards,
    ].filter(Boolean).length;
    const hasCriteria = (P) => !!P.q || countFilters(P) > 0;

    /*  Field yang sudah dinormalisasi (di-cache di objek show)  */
    const prep = (s) => s._n || (s._n = {
        title: clean(s.name),
        genres: (s.genres || []).map(clean),
        cast: clean(s.cast),
        platforms: (s.platforms || []).map(clean),
        countries: splitList(s.country).map(clean),
    });

    /*  Pencocokan teks  */
    const tokensOf = (q) => norm(q)
        .replace(/(\d{4})\s*(?:-|–|—|to|until|sampai)\s*(\d{4})/g, '$1~$2')
        .replace(/[^a-z0-9+~ ]+/g, ' ').split(/\s+/).filter((w) => w.length > 1 || /\d/.test(w));

    const decadeStart = (d) => (+d >= 3 ? 1900 + +d * 10 : 2000 + +d * 10);   // "90s" -> 1990, "10s" -> 2010
    const yearHit = (start, tok) => {
        if (!start) return 0;
        let m;
        if (/^\d{4}$/.test(tok)) return start === +tok ? 3 : 0;
        if ((m = tok.match(/^(\d{4})~(\d{4})$/))) return start >= +m[1] && start <= +m[2] ? 3 : 0;
        if ((m = tok.match(/^(\d{4})s$/))) return start >= +m[1] && start <= +m[1] + 9 ? 3 : 0;
        if ((m = tok.match(/^(\d)0s$/))) { const b = decadeStart(m[1]); return start >= b && start <= b + 9 ? 3 : 0; }
        if (/^\d{3}$/.test(tok)) return String(start).startsWith(tok) ? 2 : 0;
        return 0;
    };

    const scoreToken = (s, tok, scope) => {
        const n = prep(s);
        const all = scope === 'all';
        let best = 0;
        if (all || scope === 'title') {
            if (n.title.startsWith(tok)) best = 6;
            else if ((' ' + n.title).includes(' ' + tok)) best = 5;
            else if (n.title.includes(tok)) best = 3;
        }
        if (all || scope === 'genre') {
            if (n.genres.some((g) => g.startsWith(tok))) best = Math.max(best, 4);
            else if (n.genres.some((g) => g.includes(tok))) best = Math.max(best, 2);
        }
        if (all || scope === 'cast') {
            if ((' ' + n.cast).includes(' ' + tok)) best = Math.max(best, 3);
            else if (n.cast.includes(tok)) best = Math.max(best, 2);
        }
        if (all || scope === 'year') best = Math.max(best, yearHit(s.start, tok));
        if (all) {
            if (n.platforms.some((p) => p.includes(tok))) best = Math.max(best, 1.5);
            if (n.countries.some((c) => c.includes(tok))) best = Math.max(best, 1.5);
        }
        return best;
    };

    const scoreQuery = (s, toks, scope) => {
        let total = 0;
        for (const tok of toks) {
            const sc = scoreToken(s, tok, scope);
            if (!sc) return 0;
            total += sc;
        }
        if (toks.length > 1) {
            const phrase = toks.join(' '), n = prep(s);
            if ((scope === 'all' || scope === 'title') && n.title.includes(phrase)) total += 6;
            if ((scope === 'all' || scope === 'cast') && n.cast.includes(phrase)) total += 4;
        }
        return total;
    };

    /*  Filter + urut  */
    const byRating = (a, b) => b.rating - a.rating || b.votes - a.votes;
    const byVotes = (a, b) => b.votes - a.votes || b.rating - a.rating;
    const SORTERS = {
        rating: byRating,
        votes: byVotes,
        newest: (a, b) => b.start - a.start || byRating(a, b),
        oldest: (a, b) => (a.start || 9999) - (b.start || 9999) || byRating(a, b),
        seasons: (a, b) => b.seasons - a.seasons || byRating(a, b),
    };

    const filter = (shows, P) => {
        const toks = tokensOf(P.q);
        const gset = P.genres.map(clean);
        const plat = P.platform.map(clean);
        const ctry = P.country.map(clean);
        const out = [];

        for (const s of shows) {
            const n = prep(s);
            if (gset.length && !gset.every((g) => n.genres.includes(g))) continue;
            if (P.from != null && !(s.start && s.start >= P.from)) continue;
            if (P.to != null && !(s.start && s.start <= P.to)) continue;
            if (P.rating != null && s.rating < P.rating) continue;
            if (P.seasons != null && s.seasons < P.seasons) continue;
            if (P.smax != null && !(s.seasons && s.seasons <= P.smax)) continue;
            if (plat.length && !plat.some((p) => n.platforms.includes(p))) continue;
            if (ctry.length && !ctry.some((c) => n.countries.includes(c))) continue;
            if (P.awards && !(s.wins > 0)) continue;
            let rel = 0;
            if (toks.length) { rel = scoreQuery(s, toks, P.in); if (!rel) continue; }
            out.push([s, rel]);
        }

        const sort = P.sort || (toks.length ? 'relevance' : 'rating');
        if (sort === 'relevance') out.sort((a, b) => b[1] - a[1] || byVotes(a[0], b[0]));
        else out.sort((a, b) => (SORTERS[sort] || byRating)(a[0], b[0]));
        return out.map((x) => x[0]);
    };

    /*  Facet untuk panel filter  */
    const facets = (shows) => {
        const count = (get) => {
            const m = new Map();
            shows.forEach((s) => get(s).forEach((v) => m.set(v, (m.get(v) || 0) + 1)));
            return [...m.entries()].map(([name, n]) => ({ name, n })).sort((a, b) => b.n - a.n || a.name.localeCompare(b.name));
        };
        const years = shows.map((s) => s.start).filter(Boolean);
        return {
            genres: count((s) => s.genres || []),
            platforms: count((s) => s.platforms || []),
            countries: count((s) => splitList(s.country)),
            minYear: years.length ? Math.min(...years) : 1950,
            maxYear: years.length ? Math.max(...years) : new Date().getFullYear(),
        };
    };

    /*  Chip "kriteria aktif"  */
    const describe = (P) => {
        const chips = [];
        if (P.q) chips.push({ key: 'q', label: P.in === 'all' ? `“${P.q}”` : `“${P.q}” in ${scopeLabel(P.in)}` });
        P.genres.forEach((g) => chips.push({ key: 'genre', value: g, label: `Genre: ${g}` }));
        if (P.from != null || P.to != null) {
            chips.push({
                key: 'year',
                label: P.from != null && P.to != null ? (P.from === P.to ? `Year: ${P.from}` : `Year: ${P.from}–${P.to}`)
                    : P.from != null ? `From ${P.from}` : `Until ${P.to}`,
            });
        }
        if (P.rating != null) chips.push({ key: 'rating', label: `Rating ≥ ${P.rating}` });
        if (P.seasons != null) chips.push({ key: 'seasons', label: `Seasons ≥ ${P.seasons}` });
        if (P.smax != null) chips.push({ key: 'smax', label: P.smax === 1 ? 'Single season' : `Seasons ≤ ${P.smax}` });
        P.platform.forEach((p) => chips.push({ key: 'platform', value: p, label: `Platform: ${p}` }));
        P.country.forEach((c) => chips.push({ key: 'country', value: c, label: `Country: ${c}` }));
        if (P.awards) chips.push({ key: 'awards', label: 'Award winning' });
        return chips;
    };

    const removeChip = (P, chip) => {
        const N = clone(P);
        switch (chip.key) {
            case 'q': N.q = ''; break;
            case 'genre': N.genres = N.genres.filter((g) => g !== chip.value); break;
            case 'year': N.from = null; N.to = null; break;
            case 'rating': N.rating = null; break;
            case 'seasons': N.seasons = null; break;
            case 'smax': N.smax = null; break;
            case 'platform': N.platform = N.platform.filter((p) => p !== chip.value); break;
            case 'country': N.country = N.country.filter((c) => c !== chip.value); break;
            case 'awards': N.awards = false; break;
        }
        return N;
    };

    /*  Series mirip (genre + pemeran + negara)  */
    const similar = (shows, base, limit = 8) => {
        const bg = new Set(base.genres.map(clean));
        const bc = new Set(splitList(base.cast).map(clean));
        const bk = new Set(splitList(base.country).map(clean));
        const scored = [];
        for (const s of shows) {
            if (s.id === base.id) continue;
            const g = new Set((s.genres || []).map(clean));
            const inter = [...g].filter((x) => bg.has(x)).length;
            const cast = splitList(s.cast).map(clean).filter((x) => bc.has(x)).length;
            if (!inter && !cast) continue;
            const union = bg.size + g.size - inter;
            const ctry = splitList(s.country).some((c) => bk.has(clean(c))) ? 0.4 : 0;
            scored.push([2 * (union ? inter / union : 0) + 3 * Math.min(cast, 3) / 3 + ctry + s.rating / 100, s]);
        }
        return scored.sort((a, b) => b[0] - a[0]).slice(0, limit).map((x) => x[1]);
    };

    ITV.engine = {
        norm, clean, splitList, SCOPES, SORTS, scopeLabel, emptyParams, clone,
        paramsFromSearch, paramsToQuery, hasCriteria, countFilters,
        tokensOf, filter, facets, describe, removeChip, similar,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = ITV.engine;
})(typeof window !== 'undefined' ? window : globalThis);