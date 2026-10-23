/* Review dari TMDB (via /api/reviews/{id}). Tidak disimpan ke database. */
(() => {
    'use strict';
    const root = document.getElementById('reviews');
    if (!root) return;

    const id = root.dataset.tmdbId;
    const list = root.querySelector('#reviewList');
    const more = root.querySelector('#reviewMore');
    const count = root.querySelector('#reviewCount');
    if (!id) { root.remove(); return; }

    const STEP = 3;          // jumlah review per tampil
    const LONG = 450;        // lebih panjang dari ini -> dipotong + "Read more"
    let reviews = [], shown = 0;

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const avatarUrl = (p) => {
        if (!p) return '';
        return p.startsWith('/http') ? p.slice(1) : `https://image.tmdb.org/t/p/w92${p}`;
    };

    const fmtDate = (d) => {
        const t = new Date(d);
        return isNaN(t) ? '' : t.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    };

    const star = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.5l2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L12 17.3 6.1 20.6l1.3-6.6L2.5 9.4l6.6-.8L12 2.5z"/></svg>';

    const card = (r) => {
        const name = r.author || 'Anonymous';
        const av = avatarUrl(r.avatar);
        const long = r.content.length > LONG;
        return `
        <article class="rv">
            <header class="rv__top">
                <div class="rv__avatar">${av
                    ? `<img src="${esc(av)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">`
                    : ''}<span>${esc(name.charAt(0).toUpperCase())}</span></div>
                <div class="rv__who">
                    <strong>${esc(name)}</strong>
                    <small>${esc(fmtDate(r.date))}</small>
                </div>
                ${r.rating != null ? `<div class="rv__rate">${star}${Number(r.rating).toFixed(1)}</div>` : ''}
            </header>
            <p class="rv__text${long ? ' is-clamped' : ''}">${esc(r.content.trim())}</p>
            ${long ? '<button type="button" class="rv__toggle">Read more</button>' : ''}
        </article>`;
    };

    const render = () => {
        const next = reviews.slice(shown, shown + STEP);
        list.insertAdjacentHTML('beforeend', next.map(card).join(''));
        shown += next.length;
        more.hidden = shown >= reviews.length;
        if (!more.hidden) more.textContent = `Show more reviews (${reviews.length - shown} left)`;
    };

    const message = (text) => { list.innerHTML = `<p class="reviews__empty">${text}</p>`; };

    list.addEventListener('click', (e) => {
        const b = e.target.closest('.rv__toggle');
        if (!b) return;
        const p = b.previousElementSibling;
        const open = p.classList.toggle('is-clamped') === false;
        b.textContent = open ? 'Show less' : 'Read more';
    });
    more.addEventListener('click', render);

    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 10000);

    fetch(`/api/reviews/${encodeURIComponent(id)}`, { signal: ctrl.signal, headers: { Accept: 'application/json' } })
        .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then((d) => {
            reviews = (d.reviews || []).filter((r) => r.content && r.content.trim());
            list.innerHTML = '';
            if (!reviews.length) return message('No reviews for this series yet.');
            count.textContent = d.total || reviews.length;
            count.hidden = false;
            render();
        })
        .catch(() => message('Reviews could not be loaded right now. Please try again later.'))
        .finally(() => clearTimeout(timer));
})();