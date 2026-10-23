/* Navbar & search.
   - Navbar: search tampil sebagai ikon. Klik ikon -> bar muncul DAN langsung bisa mengetik.
   - Mengetik huruf pertama di halaman mana pun -> pindah ke /search (mode mengetik: saran terbuka,
     teks yang sudah diketik dipulihkan lewat draft). Klik isi bar yang sudah terbuka juga pindah ke /search.
   - Enter -> /search?q=... (hasil lengkap, tanpa dropdown saran).
   - Tombol filter (corong) muncul di kanan bar begitu bar terbuka, di semua halaman.
     Di /search ia membuka/menutup panel filter; di halaman lain ia pindah ke /search?filters=1.
   - Ikon search selalu ada, kecuali di Home saat posisi paling atas (belum di-scroll).
   - Tombol X selalu ada selama bar terbuka: ada teks -> hapus teks; sudah kosong -> bar menutup.
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

    // X selalu tampil selama bar terbuka
    const sync = () => { clear.hidden = !isOpen(); };

    /* Ikon disembunyikan hanya di Home saat di paling atas. Selain itu selalu tampil. */
    const AWAY_BELOW = 120;
    const updateAway = () => {
        if (!home || isOpen() || input.value) return form.classList.remove('is-away');
        form.classList.toggle('is-away', window.scrollY <= AWAY_BELOW);
    };

    /* Fokus ke input sesudah bar dibuka. */
    const focusInput = () => {
        const tryFocus = () => {
            const a = document.activeElement;
            if (isOpen() && a !== input && (a === document.body || form.contains(a))) input.focus({ preventScroll: true });
        };
        tryFocus();
        requestAnimationFrame(tryFocus);
        setTimeout(tryFocus, 120);
        setTimeout(tryFocus, 380);
    };

    const setOpen = (open, focus = true) => {
        form.classList.toggle('is-open', open);
        if (nav) nav.classList.toggle('is-searching', open);
        toggle.setAttribute('aria-expanded', String(open));
        input.tabIndex = open ? 0 : -1;
        if (!open) draft.clear();
        sync();
        updateAway();
        if (open && focus) focusInput();
        document.dispatchEvent(new CustomEvent('itv:search-toggle', { detail: { open } }));
    };

    // ikon: tutup -> buka (langsung bisa mengetik); terbuka -> kirim pencarian
    toggle.addEventListener('click', () => {
        if (!isOpen()) return setOpen(true);
        form.requestSubmit ? form.requestSubmit() : form.submit();
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

    // X: ada teks -> hapus teks. Sudah kosong -> tutup bar.
    clear.addEventListener('click', () => {
        if (input.value) {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
            return;
        }
        setOpen(false, false);
        input.blur();
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
    // klik isi bar (yang sudah terbuka) -> halaman search
    input.addEventListener('click', () => { if (isOpen()) goSearch(form, input); });

    // klik di luar / Escape -> tutup kalau kosong
    document.addEventListener('click', (e) => {
        if (isOpen() && !form.contains(e.target) && !input.value) setOpen(false, false);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isOpen()) { setOpen(false, false); input.blur(); }
    });

    let ticking = false;
    window.addEventListener('scroll', () => {
        if (!ticking) { ticking = true; requestAnimationFrame(() => { ticking = false; updateAway(); }); }
    }, { passive: true });

    sync();
    updateAway();
})();