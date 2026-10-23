/* Navbar & search.
   - Navbar: search tampil sebagai bar (pill) seperti di hero.
   - Mengetik huruf pertama di halaman mana pun -> pindah ke /search (mode mengetik: saran terbuka,
     teks yang sudah diketik dipulihkan lewat draft). Klik isi bar juga pindah ke /search.
   - Enter -> /search?q=... (hasil lengkap, tanpa dropdown saran).
   - Tombol filter (corong) selalu ada di kanan bar.
     Di /search ia membuka/menutup panel filter; di halaman lain ia pindah ke /search?filters=1.
   - Di Home (desktop), search navbar disembunyikan selama search hero masih terlihat di layar,
     dan muncul begitu search hero keluar dari viewport (IntersectionObserver).
   - Tombol X hanya tampil kalau ada teks: klik -> hapus teks.
   - Event untuk search.js: 'itv:search-toggle', 'itv:filter-toggle'; dari search.js: 'itv:filter-count', 'itv:filter-state'. */
(() => {
    'use strict';

    const nav = document.querySelector('.nav');

    /* Draft ketikan (bertahan antar halaman) */
    const DRAFT_KEY = 'itv_search_draft';
    const DRAFT_TTL = 15000;                       // draft lebih tua dari ini expired
    const draft = {
        get() {
            try {
                const d = JSON.parse(sessionStorage.getItem(DRAFT_KEY));
                return d && Date.now() - d.t < DRAFT_TTL ? String(d.v) : '';
            } catch { return ''; }
        },
        set(v) {
            try { v ? sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ v, t: Date.now() })) : sessionStorage.removeItem(DRAFT_KEY); } catch { /* storage mati: abaikan */ }
        },
        clear() { this.set(''); },
    };

    // Pindah ke /search dalam "mode mengetik": teks dibawa lewat draft, bukan lewat URL.
    const goSearch = (form, input, extra = '') => {
        if (form.classList.contains('is-going')) return;
        draft.set(input.value);
        form.classList.add('is-going');             // kursor "progress" selama menunggu halaman baru
        location.href = form.getAttribute('action') + extra;
    };

    /*  Search di hero (home)  */
    const hero = document.getElementById('heroSearch');
    if (hero) {
        const hi = hero.querySelector('input');
        hi.addEventListener('input', () => draft.set(hi.value));
        hi.addEventListener('click', () => goSearch(hero, hi));
    }

    /*  Search di navbar  */
    const form = document.getElementById('navSearch');
    if (!form) return;

    const input = form.querySelector('#navInput');
    const clear = form.querySelector('#navClear');
    const toggle = form.querySelector('#navToggle');
    const filterBtn = form.querySelector('#navFilter');
    const badge = form.querySelector('#navFilterBadge');
    const live = form.dataset.live === '1';        // halaman /search
    const home = form.dataset.home === '1';        // halaman Home
    const isOpen = () => form.classList.contains('is-open');

    // X hanya tampil kalau ada teks
    const sync = () => { clear.hidden = !input.value; };

    /* Home: search navbar disembunyikan selama search hero masih terlihat.
       (CSS .is-away hanya berlaku di desktop; di mobile search navbar selalu tampil.) */
    let heroVisible = !!hero;
    const updateAway = () => {
        if (!home || !hero || isOpen() || input.value) return form.classList.remove('is-away');
        form.classList.toggle('is-away', heroVisible);
    };

    if (home && hero && 'IntersectionObserver' in window) {
        new IntersectionObserver(([entry]) => {
            heroVisible = entry.isIntersecting;
            updateAway();
        }, { rootMargin: '-68px 0px 0px 0px' }).observe(hero);   // 68px = tinggi navbar sticky
    }

    const setOpen = (open, focus = true) => {
        form.classList.toggle('is-open', open);
        if (nav) nav.classList.toggle('is-searching', open);
        toggle.setAttribute('aria-expanded', String(open));
        if (!open) draft.clear();
        sync();
        updateAway();
        if (open && focus) input.focus({ preventScroll: true });
        document.dispatchEvent(new CustomEvent('itv:search-toggle', { detail: { open } }));
    };

    // ikon: ada teks -> kirim pencarian; kosong -> di /search fokus ke input, di halaman lain pindah ke /search
    toggle.addEventListener('click', () => {
        if (input.value.trim()) return form.requestSubmit ? form.requestSubmit() : form.submit();
        if (live) return input.focus();
        goSearch(form, input);
    });

    // tombol filter
    if (filterBtn) {
        filterBtn.addEventListener('click', () => {
            if (live) return document.dispatchEvent(new CustomEvent('itv:filter-toggle'));
            goSearch(form, input, '?filters=1');
        });
        document.addEventListener('itv:filter-count', (e) => {
            if (!badge) return;
            const n = (e.detail && e.detail.n) || 0;
            badge.textContent = n; badge.hidden = !n;
        });
        document.addEventListener('itv:filter-state', (e) => {
            filterBtn.classList.toggle('is-active', !!(e.detail && e.detail.open));
        });
    }

    input.addEventListener('input', () => {
        sync();
        draft.set(input.value);
        // mengetik di halaman lain -> langsung ke /search, teks dibawa lewat draft
        if (!live && input.value.trim()) goSearch(form, input);
    });

    // X: hapus teks, fokus tetap di input
    clear.addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    });

    /* Halaman /search: pulihkan draft, bar terbuka */
    if (live) {
        const d = draft.get();
        if (d && d !== input.value) {
            input.value = d;             // search.js jalan setelah ini dan membaca nilai ini
            input.focus();
            input.setSelectionRange(d.length, d.length);
        }
        draft.clear();
        setOpen(true, false);
        return;
    }

    /*  Halaman lain  */
    // klik isi bar -> halaman search
    input.addEventListener('click', () => goSearch(form, input));

    sync();
    updateAway();
})();