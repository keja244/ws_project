@extends('layouts.app')

@section('title', 'ITV —  Top Rated TV Shows by IMDb')

@section('content')
<main>
    <section class="hero" id="home">
        <div class="container hero__grid">
            <div class="hero__text">
                <h1>Explore the World<br>of TV Series.</h1>
                <p>Explore thousands of TV series, discover new stories,<br>and find detailed information all in one place.</p>

                <form class="search search--hero" id="heroSearch" action="{{ url('/search') }}" method="GET" role="search">
                    <input type="search" name="q" placeholder="Search TV series..." autocomplete="off" aria-label="Search TV series">
                    <button type="submit" class="search__btn" aria-label="Search">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    </button>
                </form>
            </div>

            <div class="collage" id="collage" aria-hidden="true">
                <div class="collage__tile t1"></div>
                <div class="collage__tile t2"></div>
                <div class="collage__tile b1"></div>
                <div class="collage__tile b2"></div>
                <div class="collage__tile b3"></div>
            </div>
        </div>
    </section>

    <div class="container" id="rows"></div>
</main>
@endsection

@push('scripts')
<script src="{{ asset('js/home.js') }}" defer></script>
@endpush