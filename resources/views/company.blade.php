@extends('layouts.app')

@section('title', 'Production Company — ITV')

@section('content')
<main>
    <section class="page-head">
        <div class="container">
            <a class="back" id="coBack" href="{{ url('/') }}">&larr; Back</a>
            <h1 id="coTitle"></h1>
            <p id="coStatus" aria-live="polite"></p>
        </div>
    </section>

    <section class="container">
        <div class="results results--7" id="coList"></div>
        <nav class="pager" id="coPager" aria-label="Pagination" hidden></nav>
    </section>
</main>
@endsection

@push('scripts')
<script>window.ITV_COMPANY = @json($company ?? '');</script>
<script src="{{ asset('js/company.js') }}" defer></script>
@endpush