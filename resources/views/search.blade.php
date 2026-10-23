@extends('layouts.app')

@section('title', 'Search — ITV')
@section('body_class', 'page-search')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/search.css') }}">
@endpush

@section('content')
<main class="sp">
    {{-- Dropdown saran (maks. 7). Melayang di bawah search bar; menutup saat klik di luar, buka lagi saat bar diklik. --}}
    <div class="sp__dd" id="spDd" hidden>
        <div class="sp__scopes">
            <span>Search in</span>
            <div class="seg seg--sm" id="spScopes" role="group" aria-label="Search in"></div>
        </div>
        <div class="sp__list" id="spList" aria-live="polite"></div>
    </div>

    {{-- Panel filter lanjutan (dibuka tombol corong di navbar, atau /search?filters=1) --}}
    <section class="container sp__filters" id="spFilters" hidden aria-label="Advanced search">
        <div class="fp">
            <div class="fp__head">
                <h2>Advanced search</h2>
                <button type="button" class="fp__x" id="fpClose" aria-label="Close filters">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="fp__field">
                <span class="fp__label">Search in</span>
                <div class="seg" id="fpScopes" role="group" aria-label="Search in"></div>
                <p class="fp__help" id="fpHelp"></p>
            </div>

            <div class="fp__field">
                <span class="fp__label">Genres <small>(a show must match all selected)</small></span>
                <div class="gchips" id="fpGenres"></div>
            </div>

            <div class="fp__grid">
                <label class="fp__field"><span class="fp__label">Year from</span><input type="number" id="fpFrom" inputmode="numeric" placeholder="Any"></label>
                <label class="fp__field"><span class="fp__label">Year to</span><input type="number" id="fpTo" inputmode="numeric" placeholder="Any"></label>
                <label class="fp__field"><span class="fp__label">Min. rating</span><select id="fpRating"></select></label>
                <label class="fp__field"><span class="fp__label">Seasons</span><select id="fpSeasons"></select></label>
                <label class="fp__field"><span class="fp__label">Platform</span><select id="fpPlatform"></select></label>
                <label class="fp__field"><span class="fp__label">Country</span><select id="fpCountry"></select></label>
            </div>

            <label class="fp__check"><input type="checkbox" id="fpAwards"> <span>Award winning only</span></label>

            <div class="fp__actions">
                <button type="button" class="fp__btn" id="fpReset">Reset filters</button>
                <button type="button" class="fp__btn fp__btn--primary" id="fpDone">Show results</button>
            </div>
        </div>
    </section>

    {{-- Hasil lengkap (muncul setelah Enter / filter dipakai) --}}
    <section class="container sr" id="spResults" hidden>
        <div class="sr__head">
            <div>
                <h1 id="srTitle"></h1>
                <p id="srStatus" aria-live="polite"></p>
            </div>
            <label class="sr__sort">
                <span>Sort by</span>
                <select id="srSort"></select>
            </label>
        </div>
        <div class="sr__chips" id="srChips"></div>
        <div class="results results--7" id="srGrid"></div>
        <nav class="pager" id="srPager" aria-label="Pagination" hidden></nav>
    </section>

    {{-- Tampilan awal (belum ada pencarian) --}}
    <div class="sp__hint" id="spHint">
        <p class="sp__hint-title">You can search anything...</p>
        <p>Titles, years, cast members, or genres. Use the filter button next to the search bar for advanced options.</p>
    </div>
</main>
@endsection

@push('scripts')
<script src="{{ asset('js/search-core.js') }}" defer></script>
<script src="{{ asset('js/search.js') }}" defer></script>
@endpush