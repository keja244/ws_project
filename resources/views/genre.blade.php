@extends('layouts.app')

@section('title', 'Genre — ITV')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/genre.css') }}">
@endpush

@section('content')
<main>
    <section class="page-head">
        <div class="container">
            <h1>Browse by Genre</h1>
            <p>Pick a genre and explore the best TV series in it.</p>
        </div>
    </section>

    <section class="container">
        <div class="genre-grid" id="genreGrid"></div>
        <div class="genre-more" id="genreMoreWrap">
            <button type="button" class="more-btn" id="genreMore" aria-expanded="false">See more</button>
        </div>
    </section>

    <section class="genre-results" id="genreResults" hidden>
        <div class="container">
            <div class="genre-results__head">
                <div>
                    <h2 id="resultsTitle"></h2>
                    <p class="genre-results__status" id="resultsStatus" aria-live="polite"></p>
                </div>
                <div class="genre-results__tools">
                    <form class="search search--inline" id="genreSearch" role="search">
                        <input type="search" id="genreQ" placeholder="Search in this genre..." autocomplete="off" aria-label="Search within this genre">
                        <button type="button" class="search__btn search__clear" id="genreClear" aria-label="Clear search" hidden>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                        <button type="submit" class="search__btn" aria-label="Search">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        </button>
                    </form>
                    <label class="sort">
                        <span>Sort by</span>
                        <select id="sortSelect">
                            <option value="rating">Top Rated</option>
                            <option value="votes">Most Popular</option>
                            <option value="newest">Newest</option>
                            <option value="seasons">Most Seasons</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="results results--7" id="genreShows"></div>
            <nav class="pager" id="pager" aria-label="Pagination" hidden></nav>
        </div>
    </section>
</main>
@endsection

@push('scripts')
<script src="{{ asset('js/genre.js') }}" defer></script>
@endpush