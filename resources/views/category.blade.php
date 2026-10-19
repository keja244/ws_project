@extends('layouts.app')

@section('title', 'Category — ITV')

@section('content')
<main>
    <section class="page-head">
        <div class="container">
            <a class="back" href="{{ url('/') }}">&larr; Back to Home</a>
            <h1 id="catTitle"></h1>
            <p id="catStatus" aria-live="polite"></p>
        </div>
    </section>

    <section class="container">
        <div class="results results--7" id="catList"></div>
        <nav class="pager" id="catPager" aria-label="Pagination" hidden></nav>
    </section>
</main>
@endsection

@push('scripts')
<script>window.ITV_CATEGORY = @json($slug ?? '');</script>
<script src="{{ asset('js/category.js') }}" defer></script>
@endpush