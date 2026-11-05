@extends('layouts.app')

@section('title', 'Country — ITV')

@section('content')
<main>
    <section class="page-head">
        <div class="container">
            <a class="back" id="ctBack" href="{{ url('/') }}">&larr; Back</a>
            <h1 id="ctTitle"></h1>
            <p id="ctStatus" aria-live="polite"></p>
        </div>
    </section>

    <section class="container">
        <div class="results results--7" id="ctList"></div>
        <nav class="pager" id="ctPager" aria-label="Pagination" hidden></nav>
    </section>
</main>
@endsection

@push('scripts')
<script>window.ITV_COUNTRY = @json($country ?? '');</script>
<script src="{{ asset('js/country.js') }}" defer></script>
@endpush