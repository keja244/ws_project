@extends('layouts.app')

@section('title', 'ITV — Series Detail')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/detail.css') }}">
@endpush

@section('content')
<main class="container detail" id="detail">
    {{-- diisi detail.js --}}
</main>
@endsection

@push('scripts')
<script>window.ITV_SHOW = @json($show ?? null);</script>
<script src="{{ asset('js/detail.js') }}" defer></script>
@endpush