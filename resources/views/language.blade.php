@extends('layouts.app')

@section('title', 'Language — ITV')

@section('content')
<main>
    <section class="page-head">
        <div class="container">
            <a class="back" id="lgBack" href="{{ url('/') }}">&larr; Back</a>
            <h1 id="lgTitle"></h1>
            <p id="lgStatus" aria-live="polite"></p>
        </div>
    </section>

    <section class="container">
        <div class="results results--7" id="lgList"></div>
        <nav class="pager" id="lgPager" aria-label="Pagination" hidden></nav>
    </section>
</main>
@endsection

@push('scripts')
<script>window.ITV_LANGUAGE = @json($language ?? '');</script>
<script src="{{ asset('js/language.js') }}" defer></script>
@endpush