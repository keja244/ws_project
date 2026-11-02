/* Halaman production company: /company?c=Sony+Pictures+Television
   Server sudah menyaring series dari company itu; di sini tinggal urut + pagination. */
(() => {
    'use strict';
    const { $, esc, SHOWS, USING_PLACEHOLDER, byRating, cardHTML, renderPager, getPage, setParams } = window.ITV;

    const PER_PAGE = 21;
    const company = window.ITV_COMPANY || '';
    const items = USING_PLACEHOLDER ? [] : [...SHOWS].sort(byRating);

    const title = $('#coTitle'), status = $('#coStatus'), list = $('#coList'), pager = $('#coPager'), back = $('#coBack');

    // "Back" kembali ke halaman detail kalau datang dari sana
    back.addEventListener('click', (e) => {
        if (history.length > 1 && document.referrer.startsWith(location.origin)) {
            e.preventDefault();
            history.back();
        }
    });

    if (!company) {
        title.textContent = 'No company selected';
        status.textContent = 'Open a series and click one of its production companies.';
        return;
    }

    title.textContent = `Production Company: “${company}”`;
    document.title = `${company} — ITV`;
    let page = getPage();

    const render = (scroll) => {
        const total = items.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        page = Math.min(Math.max(page, 1), pages);
        const from = (page - 1) * PER_PAGE;

        list.innerHTML = total
            ? items.slice(from, from + PER_PAGE).map(cardHTML).join('')
            : `<p class="results__empty">No series from “${esc(company)}”.</p>`;
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