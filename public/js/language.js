(() => {
    'use strict';
    const { $, esc, SHOWS, USING_PLACEHOLDER, byRating, cardHTML, renderPager, getPage, setParams } = window.ITV;

    const PER_PAGE = 21;
    const entity = window.ITV_LANGUAGE || '';
    const items = USING_PLACEHOLDER ? [] : [...SHOWS].sort(byRating);

    const title = $('#lgTitle'), status = $('#lgStatus'), list = $('#lgList'), pager = $('#lgPager'), back = $('#lgBack');

    back.addEventListener('click', (e) => {
        if (history.length > 1 && document.referrer.startsWith(location.origin)) {
            e.preventDefault();
            history.back();
        }
    });

    if (!entity) {
        title.textContent = 'No language selected';
        status.textContent = 'Open a series and click one of its spoken languages.';
        return;
    }

    title.textContent = `Language: “${entity}”`;
    document.title = `${entity} — ITV`;
    let page = getPage();

    const render = (scroll) => {
        const total = items.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        page = Math.min(Math.max(page, 1), pages);
        const from = (page - 1) * PER_PAGE;

        list.innerHTML = total
            ? items.slice(from, from + PER_PAGE).map(cardHTML).join('')
            : `<p class="results__empty">No series in “${esc(entity)}”.</p>`;
        status.textContent = total
            ? `${(from + 1).toLocaleString()}–${Math.min(from + PER_PAGE, total).toLocaleString()} of ${total.toLocaleString()} show${total === 1 ? '' : 's'}`
            : '';
        renderPager(pager, {
            page, pages,
            onChange: (p) => { page = p; setParams({ page: p > 1 ? p : null }, 'push'); render(true); },
        });
        if (scroll) window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    window.addEventListener('popstate', () => { page = getPage(); render(false); });
    render(false);
})();