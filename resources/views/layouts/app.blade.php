<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ITV —  Top Rated TV Shows by IMDb')</title>

    <link rel="icon" type="image/svg+xml" href="{{ asset('images/favicon4.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/home.css') }}">
    <link rel="stylesheet" href="{{ asset('css/nav.css') }}">
    <link rel="stylesheet" href="{{ asset('css/chatbot.css') }}">
    @stack('styles')
</head>
<body class="@yield('body_class')">

@include('partials.navbar')
@include('partials.chatbot')

@yield('content')

<footer class="footer">
    <div class="container">Information courtesy of IMDb (<a href="https://www.imdb.com" target="_blank" rel="noopener noreferrer">imdb.com</a>). Used with permission. This product uses the TMDB API but is not endorsed or certified by TMDB.</div>
</footer>

<script>window.ITV_SHOWS = @json($shows ?? []);</script>
<script src="{{ asset('js/common.js') }}" defer></script>
<script src="{{ asset('js/nav.js') }}" defer></script>
<script src="{{ asset('js/chatbot.js') }}" defer></script>
@stack('scripts')
</body>
</html>