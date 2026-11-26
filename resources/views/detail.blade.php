@extends('layouts.app')

@section('title', isset($show['name']) ? $show['name'] . ' — ITV' : 'ITV — Series Detail')

@push('meta')
    @if(!empty($show))
        <meta property="og:type" content="video.tv_show">
        <meta property="og:title" content="{{ $show['name'] ?? 'Series Detail' }} — ITV">
        @if(!empty($show['description']))
            <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($show['description']), 160) }}">
        @endif
        @if(!empty($show['image']))
            <meta property="og:image" content="{{ $show['image'] }}">
        @endif
        <meta property="og:url" content="{{ url()->current() }}">
    @endif
@endpush

@push('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<link rel="stylesheet" href="{{ asset('css/detail.css') }}">
@endpush

@section('content')
<main class="container detail" id="detail">
    {{-- diisi detail.js --}}
</main>

@if(!empty($show))
<section vocab="https://schema.org/" typeof="TVSeries" hidden>
    <span property="name">{{ $show['name'] ?? '' }}</span>
    @if(!empty($show['description']))
        <span property="description">{{ $show['description'] }}</span>
    @endif
    @if(!empty($show['image']))
        <link property="image" href="{{ $show['image'] }}">
    @endif
    @if(!empty($show['startDate']))
        <meta property="startDate" content="{{ $show['startDate'] }}">
    @endif
    @if(!empty($show['numberOfSeasons']))
        <meta property="numberOfSeasons" content="{{ $show['numberOfSeasons'] }}">
    @endif
    <div property="aggregateRating" typeof="AggregateRating">
        <meta property="ratingValue" content="{{ $show['aggregateRating'] ?? 0 }}">
        <meta property="ratingCount" content="{{ $show['interactionCount'] ?? 0 }}">
        <meta property="bestRating" content="{{ $show['bestRating'] ?? 10 }}">
    </div>
    @foreach(($show['actor'] ?? []) as $cast)
        <span property="actor" typeof="Person">
            <meta property="name" content="{{ $cast['name'] }}">
        </span>
    @endforeach
    @foreach(($show['genre'] ?? []) as $g)
        <meta property="genre" content="{{ $g['name'] }}">
    @endforeach
</section>
@endif
@endsection

@push('scripts')
<script>window.ITV_SHOW = @json($show ?? null);</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
<script src="{{ asset('js/detail.js') }}" defer></script>
@endpush