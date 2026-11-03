/* Halaman "See more" per kategori home: /category/{slug} */
(() => {
    'use strict';
    const { $, CATEGORIES, normalize, cardHTML, renderPager, getPage, setParams } = window.ITV;

    const PER_PAGE = 21;                                  
    const cat = CATEGORIES.find((c) => c.slug === window.ITV_CATEGORY);

    const title = $('#catTitle'), status = $('#catStatus'), list = $('#catList'), pager = $('#catPager');

    if (!cat) {
        title.textContent = 'Category not found';
        status.textContent = 'Pick a category from the home page.';
        return;
    }

    title.textContent = cat.title;
    document.title = `${cat.title} — ITV`;
    let page = getPage();
    let seq = 0;                                          // abaikan respons lama kalau halaman cepat berganti

    // Isi kategori dihitung di SPARQL (ShowController::categoryShows), bukan dari cat.get()
    const render = async (scroll) => {
        const mine = ++seq;
        status.textContent = 'Loading…';

        let data;
        try {
            const res = await fetch(`/api/category/${encodeURIComponent(cat.slug)}?page=${page}&per=${PER_PAGE}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            data = await res.json();
        } catch (err) {
            if (mine !== seq) return;
            list.innerHTML = '<p class="results__empty">Could not load this category. Please try again.</p>';
            status.textContent = '';
            pager.hidden = true;
            return;
        }
        if (mine !== seq) return;

        const items = data.shows.map(normalize);
        const total = data.total;
        const pages = data.pages;
        page = data.page;
        const from = (page - 1) * PER_PAGE;

        list.innerHTML = total
            ? items.map(cardHTML).join('')
            : '<p class="results__empty">No shows in this category yet.</p>';
        status.textContent = total ? `${(from + 1).toLocaleString()}–${Math.min(from + PER_PAGE, total).toLocaleString()} of ${total.toLocaleString()} shows` : '';
        renderPager(pager, {
            page, pages,
            onChange: (p) => { page = p; setParams({ page: p > 1 ? p : null }, 'push'); render(true); },
        });
        if (scroll) window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    window.addEventListener('popstate', () => { page = getPage(); render(false); });
    render(false);
})();