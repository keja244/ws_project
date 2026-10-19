/* Navbar.
   - Mobile: saat scroll ke bawah navbar jadi tipis (baris search disembunyikan),
     muncul lagi kalau scroll ke atas atau kembali ke paling atas.
   - Di halaman lain: begitu search diklik/fokus, langsung pindah ke /search.
   - Di halaman /search: input live (diurus search.js), tombol clear tampil kalau ada isi. */
(() => {
    'use strict';

    /* ---------- Navbar tipis saat scroll ---------- */
    const nav = document.querySelector('.nav');
    if (nav) {
        let lastY = window.scrollY, ref = lastY, dir = 0, lock = 0, ticking = false;

        const set = (compact) => {
            if (nav.classList.contains('is-compact') === compact) return;
            nav.classList.toggle('is-compact', compact);
            lock = performance.now() + 450;      
        };

        const onScroll = () => {
            ticking = false;
            const y = Math.max(0, window.scrollY);
            if (performance.now() < lock) { lastY = y; return; }
            if (y < 16) { set(false); lastY = ref = y; dir = 0; return; }

            const d = y - lastY;
            if (!d) return;
            if (Math.sign(d) !== dir) { dir = Math.sign(d); ref = lastY; }
            lastY = y;

            const travel = Math.abs(y - ref);
            if (dir > 0 && y > 80 && travel > 30) set(true);
            else if (dir < 0 && travel > 60) set(false);
        };

        window.addEventListener('scroll', () => {
            if (!ticking) { ticking = true; requestAnimationFrame(onScroll); }
        }, { passive: true });
    }

    /* ---------- Search di navbar ---------- */
    const form = document.getElementById('navSearch');
    if (!form) return;

    const input = form.querySelector('#navInput');
    const clear = form.querySelector('#navClear');

    if (form.dataset.live !== '1') {
        const go = () => { location.href = form.getAttribute('action'); };
        input.addEventListener('focus', go);
        input.addEventListener('click', go);
        return;
    }

    const sync = () => { clear.hidden = !input.value; };
    input.addEventListener('input', sync);
    clear.addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    });
    sync();
})();