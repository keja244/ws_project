@extends('layouts.app')

@section('title', 'Top Rated — ITV')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/top-rated.css') }}">
@endpush

@section('content')
<main class="container ranking">
    <div class="ranking__list" id="rankList"></div>
    <div class="ranking__more">
        <p id="rankStatus"></p>
        <nav class="pager" id="rankPager" aria-label="Pagination" hidden></nav>
    </div>
</main>
@endsection

@push('scripts')
<script src="{{ asset('js/top-rated.js') }}" defer></script>
@endpush