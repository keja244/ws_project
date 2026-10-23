/* Halaman /search: pencarian menyeluruh + pencarian per bidang + filter lanjutan.

   Mode:
   - Mengetik : tidak ada hasil lengkap; dropdown saran (maks. 7) tampil di bawah bar.
   - Hasil    : /search?q=...&in=cast&genre=Drama|Crime&from=1990&... -> grid + pagination, dropdown tertutup.
   Enter / ikon search / "See all ..." -> mode Hasil. Filter di panel langsung memperbarui hasil.
   Dropdown: klik di luar -> tertutup, klik bar lagi -> terbuka lagi. */
(() => {
    'use strict';
    const { $, esc, SHOWS, cardHTML, renderPager, getPage } = window.ITV;
    const E = window.ITV.engine;
    if (!E) {
        console.error('[ITV] search-core.js belum dimuat. Pastikan file public/js/search-core.js ada, dan resources/views/search.blade.php versi baru terpasang (memuat search-core.js sebelum search.js).');
        return;
    }

    const DETAIL_URL = window.ITV_DETAIL_URL || '/detail/';
    const LIMIT = 7;            // saran di dropdown
    const PER_PAGE = 21;        // hasil per halaman (3 baris x 7)
    const fmt = (n) => n.toLocaleString('en-US');
    const FAC = E.facets(SHOWS);

    const input = $('#navInput'), form = $('#navSearch');
    const dd = $('#spDd'), list = $('#spList'), ddScopes = $('#spScopes');
    const panel = $('#spFilters'), fpScopes = $('#fpScopes'), fpHelp = $('#fpHelp'), fpGenres = $('#fpGenres');
    const fpFrom = $('#fpFrom'), fpTo = $('#fpTo'), fpRating = $('#fpRating'), fpSeasons = $('#fpSeasons');
    const fpPlatform = $('#fpPlatform'), fpCountry = $('#fpCountry'), fpAwards = $('#fpAwards');
    const section = $('#spResults'), title = $('#srTitle'), status = $('#srStatus'), sortSel = $('#srSort');
    const chipsEl = $('#srChips'), grid = $('#srGrid'), pager = $('#srPager'), hint = $('#spHint');

    const PLACEHOLDER = {
        all: 'Search TV series...', title: 'Search by title...', genre: 'Search by genre...',
        year: 'Search by year, e.g. 1999 or 1990s', cast: 'Search by cast, e.g. Bryan Cranston',
    };
    const HELP = {
        all: 'Matches title, genre, year, cast, platform and country.',
        title: 'Only looks at series titles.',
        genre: 'Only looks at genres, e.g. “crime”.',
        year: 'Premiere year: 1999, a decade like 1990s, or a range like 1995-2005.',
        cast: 'Only looks at cast members, e.g. “bryan cranston”.',
    };

    let P = E.paramsFromSearch(location.search);
    let page = getPage();
    let applied = false;        // grid hasil sedang tampil
    let t;

    /*  Panel filter  */
    const opt = (v, label, sel) => `<option value="${esc(v)}"${sel ? ' selected' : ''}>${esc(label)}</option>`;

    const scopeButtons = () => E.SCOPES.map(([k, l]) => `<button type="button" class="seg__b" data-scope="${k}">${l}</button>`).join('');
    ddScopes.innerHTML = scopeButtons();
    fpScopes.innerHTML = scopeButtons();

    fpGenres.innerHTML = FAC.genres.map((g) =>
        `<button type="button" class="gchip" data-g="${esc(g.name)}">${esc(g.name)} <small>${fmt(g.n)}</small></button>`).join('');

    fpRating.innerHTML = [['', 'Any'], [6, '6.0+'], [7, '7.0+'], [7.5, '7.5+'], [8, '8.0+'], [8.5, '8.5+'], [9, '9.0+']].map(([v, l]) => opt(v, l)).join('');
    fpSeasons.innerHTML = [['', 'Any'], [2, '2+ seasons'], [3, '3+ seasons'], [5, '5+ seasons'], [8, '8+ seasons'], [10, '10+ seasons']].map(([v, l]) => opt(v, l)).join('');
    fpPlatform.innerHTML = opt('', 'Any') + FAC.platforms.map((p) => opt(p.name, `${p.name} (${p.n})`)).join('');
    fpCountry.innerHTML = opt('', 'Any') + FAC.countries.map((c) => opt(c.name, `${c.name} (${c.n})`)).join('');
    sortSel.innerHTML = E.SORTS.map(([k, l]) => opt(k, l)).join('');
    fpFrom.min = fpTo.min = FAC.minYear; fpFrom.max = fpTo.max = FAC.maxYear;

    const syncPanel = () => {
        document.querySelectorAll('[data-scope]').forEach((b) => b.classList.toggle('is-on', b.dataset.scope === P.in));
        fpHelp.textContent = HELP[P.in];
        input.placeholder = PLACEHOLDER[P.in];
        fpGenres.querySelectorAll('.gchip').forEach((b) => b.classList.toggle('is-on', P.genres.includes(b.dataset.g)));
        fpFrom.value = P.from ?? ''; fpTo.value = P.to ?? '';
        fpRating.value = P.rating ?? '';
        fpSeasons.value = P.seasons ?? '';
        fpPlatform.value = P.platform.length === 1 ? P.platform[0] : '';
        fpCountry.value = P.country.length === 1 ? P.country[0] : '';
        fpAwards.checked = P.awards;
        sortSel.value = P.sort || (P.q ? 'relevance' : 'rating');
    };

    const announceCount = () => document.dispatchEvent(new CustomEvent('itv:filter-count', { detail: { n: E.countFilters(P) } }));
    const togglePanel = (open = panel.hidden) => {
        panel.hidden = !open;
        if (open) closeDd();
        document.dispatchEvent(new CustomEvent('itv:filter-state', { detail: { open } }));
    };

    /*  URL  */
    const buildUrl = () => {
        const qs = E.paramsToQuery(P, { page: page > 1 ? page : '' });
        return location.pathname + (qs ? `?${qs}` : '');
    };
    const writeUrl = (push) => {
        const url = buildUrl();
        const same = url === location.pathname + location.search;
        history[push && !same ? 'pushState' : 'replaceState'](null, '', url);
    };

    /*  Dropdown saran  */
    const highlight = (text, toks) => {
        const s = String(text || '');
        if (!s) return '';
        const low = s.toLowerCase(), r = [];
        toks.forEach((tk) => { const i = low.indexOf(tk); if (i >= 0) r.push([i, i + tk.length]); });
        if (!r.length) return esc(s);
        r.sort((a, b) => a[0] - b[0]);
        const m = [r[0]];
        r.slice(1).forEach(([a, b]) => { const l = m[m.length - 1]; a <= l[1] ? (l[1] = Math.max(l[1], b)) : m.push([a, b]); });
        let out = '', pos = 0;
        m.forEach(([a, b]) => { out += esc(s.slice(pos, a)) + '<mark>' + esc(s.slice(a, b)) + '</mark>'; pos = b; });
        return out + esc(s.slice(pos));
    };

    const rowHTML = (s, toks) => `
        <a class="sp-row" href="${DETAIL_URL}${encodeURIComponent(s.id)}">
            <div class="sp-row__title">${highlight(s.name, toks)}${s.start ? `<span class="sp-row__year">(${s.start})</span>` : ''}</div>
            <div class="sp-row__sub">
                <span class="sp-row__cast">${highlight(s.cast, toks)}</span>
                <span class="sp-row__genre">${highlight(s.genre, toks)}</span>
            </div>
        </a>`;

    const renderSuggest = () => {
        const N = E.clone(P);
        N.q = input.value.trim();
        N.sort = N.q ? 'relevance' : 'votes';
        const all = E.filter(SHOWS, N);
        const top = all.slice(0, LIMIT);
        const toks = E.tokensOf(N.q);
        const scopeNote = N.in === 'all' ? '' : ` in ${E.scopeLabel(N.in).toLowerCase()}`;

        list.innerHTML = top.length
            ? `<div class="sp__head">${N.q ? `Top results for “${esc(N.q)}”${scopeNote}` : 'Most popular series'}</div>`
                + top.map((s) => rowHTML(s, toks)).join('')
                + (all.length > top.length || N.q ? `<button type="button" class="sp__all" id="spAll">See all ${fmt(all.length)} result${all.length === 1 ? '' : 's'}${N.q ? ` for “${esc(N.q)}”` : ''}</button>` : '')
            : '<p class="sp__empty">No series found. Try another search, scope, or fewer filters.</p>';
    };

    const openDd = () => { if (!dd.hidden) return; renderSuggest(); dd.hidden = false; };
    const closeDd = () => { dd.hidden = true; };

    /*  Hasil lengkap  */
    const renderChips = () => {
        const chips = E.describe(P);
        chipsEl.hidden = !chips.length;
        chipsEl.innerHTML = chips.map((c, i) =>
            `<span class="fchip">${esc(c.label)}<button type="button" data-i="${i}" aria-label="Remove ${esc(c.label)}">×</button></span>`).join('')
            + (chips.length > 1 ? '<button type="button" class="fchip fchip--clear" id="srClearAll">Clear all</button>' : '');
    };

    const renderResults = (scroll) => {
        const all = E.filter(SHOWS, P);
        const total = all.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        page = Math.min(Math.max(page, 1), pages);
        const from = (page - 1) * PER_PAGE;

        applied = true;
        hint.hidden = true;
        section.hidden = false;
        title.textContent = P.q ? `Results for “${P.q}”` : 'Filtered results';
        document.title = `${P.q || 'Filtered results'} — Search — ITV`;
        sortSel.value = P.sort || (P.q ? 'relevance' : 'rating');
        renderChips();

        grid.innerHTML = total
            ? all.slice(from, from + PER_PAGE).map(cardHTML).join('')
            : '<p class="results__empty">No series match these criteria. Try another scope, or remove a filter.</p>';
        status.textContent = total
            ? `${fmt(from + 1)}–${fmt(Math.min(from + PER_PAGE, total))} of ${fmt(total)} result${total === 1 ? '' : 's'}`
            : '0 results';
        renderPager(pager, {
            page, pages,
            onChange: (p) => { page = p; writeUrl(true); renderResults(true); },
        });
        if (scroll) window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const clearResults = () => {
        applied = false; page = 1;
        section.hidden = true; grid.innerHTML = ''; hint.hidden = false;
        document.title = 'Search — ITV';
    };

    // perubahan kriteria (filter, scope, sort, chip): perbarui URL + hasil
    const apply = ({ push = false } = {}) => {
        page = 1;
        writeUrl(push);
        announceCount();
        if (!E.hasCriteria(P)) clearResults(); else renderResults(false);
        if (!dd.hidden) renderSuggest();
    };

    // Enter / ikon / "See all": mode Hasil, dropdown ditutup
    const submit = () => {
        clearTimeout(t);
        P.q = input.value.trim();
        if (!E.hasCriteria(P)) {
            clearResults(); writeUrl(false); announceCount();
            return openDd();
        }
        page = 1;
        writeUrl(true);
        announceCount();
        renderResults(false);
        closeDd();
        input.blur();
    };

    /*  Event: search bar  */
    input.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => {
            // teks dikosongkan (mis. tombol X) dan tidak ada filter -> hasil ikut direset
            if (!input.value.trim() && applied && !E.hasCriteria({ ...P, q: '' })) {
                P.q = ''; clearResults(); writeUrl(false);
            }
            renderSuggest();
            dd.hidden = false;
        }, 150);
    });
    form.addEventListener('submit', (e) => { e.preventDefault(); submit(); });
    dd.addEventListener('click', (e) => {
        if (e.target.closest('#spAll')) return submit();
        const b = e.target.closest('[data-scope]');
        if (b) { setScope(b.dataset.scope); input.focus(); }
    });

    // klik bar -> dropdown terbuka lagi 
    const bornAt = performance.now();
    let quiet = false;
    const onFocusOpen = () => { if (quiet && performance.now() - bornAt < 600) return; openDd(); };
    input.addEventListener('focus', onFocusOpen);
    input.addEventListener('click', openDd);

    // klik di luar bar & dropdown -> dropdown tertutup
    document.addEventListener('click', (e) => {
        if (dd.hidden) return;
        if (form.contains(e.target) || dd.contains(e.target)) return;
        closeDd();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !dd.hidden) { closeDd(); input.blur(); }
    });

    // nav.js: bar dibuka/ditutup (ikon / X kosong) dan tombol filter
    document.addEventListener('itv:search-toggle', (e) => { e.detail.open ? openDd() : closeDd(); });
    document.addEventListener('itv:filter-toggle', () => togglePanel());

    /*  Event: scope & filter  */
    const setScope = (k) => {
        P.in = k;
        P.q = input.value.trim();
        syncPanel();
        if (applied || E.countFilters(P)) apply(); else { writeUrl(false); if (!dd.hidden) renderSuggest(); }
    };
    fpScopes.addEventListener('click', (e) => { const b = e.target.closest('[data-scope]'); if (b) setScope(b.dataset.scope); });

    const changed = () => { P.q = input.value.trim(); apply(); };

    fpGenres.addEventListener('click', (e) => {
        const b = e.target.closest('.gchip'); if (!b) return;
        const g = b.dataset.g;
        P.genres = P.genres.includes(g) ? P.genres.filter((x) => x !== g) : [...P.genres, g];
        b.classList.toggle('is-on', P.genres.includes(g));
        changed();
    });
    const numOrNull = (v) => { const n = parseInt(v, 10); return Number.isFinite(n) ? n : null; };
    let ty;
    const yearChanged = () => { clearTimeout(ty); ty = setTimeout(() => { P.from = numOrNull(fpFrom.value); P.to = numOrNull(fpTo.value); changed(); }, 300); };
    fpFrom.addEventListener('input', yearChanged);
    fpTo.addEventListener('input', yearChanged);
    fpRating.addEventListener('change', () => { P.rating = fpRating.value === '' ? null : parseFloat(fpRating.value); changed(); });
    fpSeasons.addEventListener('change', () => { P.seasons = fpSeasons.value === '' ? null : parseInt(fpSeasons.value, 10); P.smax = null; changed(); });
    fpPlatform.addEventListener('change', () => { P.platform = fpPlatform.value ? [fpPlatform.value] : []; changed(); });
    fpCountry.addEventListener('change', () => { P.country = fpCountry.value ? [fpCountry.value] : []; changed(); });
    fpAwards.addEventListener('change', () => { P.awards = fpAwards.checked; changed(); });
    sortSel.addEventListener('change', () => { P.sort = sortSel.value; changed(); });

    $('#fpReset').addEventListener('click', () => {
        const keep = { q: input.value.trim(), in: P.in, sort: P.sort };
        P = Object.assign(E.emptyParams(), keep);
        syncPanel(); apply();
    });
    $('#fpDone').addEventListener('click', () => { P.q = input.value.trim(); apply({ push: true }); togglePanel(false); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    $('#fpClose').addEventListener('click', () => togglePanel(false));

    // chip kriteria aktif: hapus satu / hapus semua
    chipsEl.addEventListener('click', (e) => {
        if (e.target.closest('#srClearAll')) {
            P = E.emptyParams(); input.value = '';
            syncPanel(); return apply();
        }
        const b = e.target.closest('button[data-i]'); if (!b) return;
        const chip = E.describe(P)[+b.dataset.i];        // sesuai chip yang sedang tampil
        if (chip.key === 'q') input.value = '';
        P = E.removeChip(P, chip);
        P.q = input.value.trim();
        syncPanel(); apply();
    });

    // tombol Back / Forward
    window.addEventListener('popstate', () => {
        P = E.paramsFromSearch(location.search); page = getPage();
        input.value = P.q; syncPanel(); announceCount();
        if (E.hasCriteria(P)) { renderResults(false); closeDd(); } else clearResults();
    });

    /*  Awal halaman  */
    const fromUrl = E.hasCriteria(P);
    syncPanel();
    announceCount();
    if (new URLSearchParams(location.search).get('filters') === '1') togglePanel(true);

    if (fromUrl) {                          // datang lewat Enter / link: hasil lengkap, TANPA dropdown saran
        input.value = P.q;
        quiet = true;
        renderResults(false);
        closeDd();
        input.blur();
    } else {                                // mode mengetik: dropdown terbuka (teks hasil draft ikut terbaca)
        if (panel.hidden) openDd();
    }
})();