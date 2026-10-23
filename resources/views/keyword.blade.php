@extends('layouts.app')

@section('title', 'Keyword — ITV')

@section('content')
<main>
    <section class="page-head">
        <div class="container">
            <a class="back" id="kwBack" href="{{ url('/') }}">&larr; Back</a>
            <h1 id="kwTitle"></h1>
            <p id="kwStatus" aria-live="polite"></p>
        </div>
    </section>

    <section class="container">
        <div class="results results--7" id="kwList"></div>
        <nav class="pager" id="kwPager" aria-label="Pagination" hidden></nav>
    </section>
</main>
@endsection

@push('scripts')
<script>window.ITV_KEYWORD = @json($keyword ?? '');</script>
<script src="{{ asset('js/keyword.js') }}" defer></script>
@endpush