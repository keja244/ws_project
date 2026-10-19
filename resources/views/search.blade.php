@extends('layouts.app')

@section('title', 'Search — ITV')
@section('body_class', 'page-search')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/search.css') }}">
@endpush

@section('content')
<main class="sp">
    <div class="sp__list" id="spList" aria-live="polite"></div>
</main>
@endsection

@push('scripts')
<script src="{{ asset('js/search.js') }}" defer></script>
@endpush