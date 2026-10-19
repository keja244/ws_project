@extends('layouts.app')

@section('title', 'ITV — Top 5000 TV Shows by IMDb')

@section('content')
<main>
    <section class="hero" id="home">
        <div class="container hero__grid">
            <div class="hero__text">
                <h1>Explore the World<br>of TV Series.</h1>
                <p>Explore thousands of TV series, discover new stories,<br>and find detailed information all in one place.</p>
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