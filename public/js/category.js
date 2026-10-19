/* Halaman "See more" per kategori home: /category/{slug} */
(() => {
    'use strict';
    const { $, CATEGORIES, cardHTML, renderPager, getPage, setParams } = window.ITV;

    const PER_PAGE = 21;                                  
    const cat = CATEGORIES.find((c) => c.slug === window.ITV_CATEGORY);

    const title = $('#catTitle'), status = $('#catStatus'), list = $('#catList'), pager = $('#catPager');

    if (!cat) {
        title.textContent = 'Category not found';
        status.textContent = 'Pick a category from the home page.';
        return;
    }

    const items = cat.get();
    title.textContent = cat.title;
    document.title = `${cat.title} — ITV`;
    let page = getPage();

    const render = (scroll) => {
        const total = items.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        page = Math.min(Math.max(page, 1), pages);
        const from = (page - 1) * PER_PAGE;

        list.innerHTML = total
            ? items.slice(from, from + PER_PAGE).map(cardHTML).join('')
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