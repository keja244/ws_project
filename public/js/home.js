(() => {
    'use strict';
    const { $, $$, esc, SHOWS, CATEGORIES, cardHTML, state, byRating, sortBy } = window.ITV;

    const ROW_LIMIT = 20;  // kartu per baris di home
    const CATEGORY_URL = window.ITV_CATEGORY_URL || '/category/';

    const ARROW_L = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';
    const ARROW_R = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

    const rowHTML = (r) => `
        <section class="row reveal" id="${r.slug}">
            <div class="row__head">
                <h2>${esc(r.title)}</h2>
                <div class="row__actions">
                    ${r.fixed ? '' : `<a class="row__more" href="${CATEGORY_URL}${r.slug}">See more</a>`}
                    <div class="row__arrows">
                        <button type="button" class="arrow" data-dir="-1" aria-label="Previous">${ARROW_L}</button>
                        <button type="button" class="arrow" data-dir="1" aria-label="Next">${ARROW_R}</button>
                    </div>
                </div>
            </div>
            <div class="row__track">${r.list.map(cardHTML).join('')}</div>
        </section>`;

    const rows = CATEGORIES
        .map((c) => ({ slug: c.slug, title: c.title, fixed: !!c.fixed, list: c.get().slice(0, ROW_LIMIT) }))
        .filter((r) => r.list.length);
    $('#rows').innerHTML = rows.map(rowHTML).join('');

    const initRow = (row) => {
        const track = $('.row__track', row);
        const [prev, next] = $$('.arrow', row);

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
            down = true; state.dragged = false; startX = e.clientX; startLeft = track.scrollLeft;
        });
        window.addEventListener('pointermove', (e) => {
            if (!down) return;
            const dx = e.clientX - startX;
            if (Math.abs(dx) > 5) { state.dragged = true; track.classList.add('is-dragging'); }
            track.scrollLeft = startLeft - dx;
        });
        window.addEventListener('pointerup', () => { down = false; track.classList.remove('is-dragging'); });
    };
    $$('.row').forEach(initRow);

    /*  Collage poster di hero  */
    const posters = [...new Set(sortBy(SHOWS, byRating).map((s) => s.image).filter(Boolean))];
    const tiles = $$('.collage__tile');
    if (posters.length) {
        let cursor = 0;
        const swap = (tile) => {
            const url = posters[cursor++ % posters.length];
            const front = $$('img', tile).find((i) => i.classList.contains('is-front'));
            if (front && front.src === url) return;
            const img = new Image();
            img.alt = ''; img.referrerPolicy = 'no-referrer'; img.draggable = false;
            img.onload = () => {
                tile.appendChild(img);
                requestAnimationFrame(() => {
                    img.classList.add('is-front');
                    if (front) { front.classList.remove('is-front'); setTimeout(() => front.remove(), 1200); }
                });
            };
            img.src = url;
        };
        // isi awal kelima tile, lalu ganti satu per satu
        tiles.forEach(swap);
        if (posters.length > 1) {
            let k = 0;
            setInterval(() => swap(tiles[k++ % tiles.length]), 3500);
        }
    }

    const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { threshold: 0.12 });
    $$('.reveal').forEach((el) => io.observe(el));
})();