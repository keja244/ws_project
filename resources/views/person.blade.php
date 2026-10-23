@extends('layouts.app')

@section('title', 'Person — ITV')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/person.css') }}">
@endpush

@section('content')
<main>
    <section class="page-head">
        <div class="container">
            <a class="back" id="pBack" href="{{ url('/') }}">&larr; Back</a>
            <h1 id="pTitle"></h1>
            <p id="pStatus" aria-live="polite"></p>
            <div class="ptabs" id="pTabs" role="group" aria-label="Filter by role" hidden></div>
        </div>
    </section>

    <section class="container">
        <div class="results results--7" id="pList"></div>
        <nav class="pager" id="pPager" aria-label="Pagination" hidden></nav>
    </section>
</main>
@endsection

@push('scripts')
<script>window.ITV_PERSON = @json($person ?? '');</script>
<script src="{{ asset('js/person.js') }}" defer></script>
@endpush