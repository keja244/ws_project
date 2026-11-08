window.ITV.initBrowse({
    label: 'Genre',
    pathName: 'genre',
    base: window.ITV_GENRE_URL || '/genre/',
    api: window.ITV_GENRE_API || '/api/genre/',
    tiles: window.ITV_GENRES,
    initial: window.ITV_GENRE,
    emptyText: 'No genre data available yet.',
    ids: {
        grid: 'genreGrid', moreWrap: 'genreMoreWrap', more: 'genreMore', tilePager: 'genreTilePager',
        results: 'genreResults', title: 'resultsTitle', status: 'resultsStatus', list: 'genreShows',
        sort: 'sortSelect', pager: 'pager', form: 'genreSearch', q: 'genreQ', clear: 'genreClear',
    },
});