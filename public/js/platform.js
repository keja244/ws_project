window.ITV.initBrowse({
    label: 'Platform',
    pathName: 'platform',
    base: window.ITV_PLATFORM_URL || '/platform/',
    api: window.ITV_PLATFORM_API || '/api/platform/',
    tiles: window.ITV_PLATFORMS,
    initial: window.ITV_PLATFORM,
    emptyText: 'No platform data available yet.',
    ids: {
        grid: 'platformGrid', moreWrap: 'platformMoreWrap', more: 'platformMore', tilePager: 'platformTilesPager',
        results: 'platformResults', title: 'resultsTitle', status: 'resultsStatus', list: 'platformShows',
        sort: 'sortSelect', pager: 'pager', form: 'platformSearch', q: 'platformQ', clear: 'platformClear',
    },
});