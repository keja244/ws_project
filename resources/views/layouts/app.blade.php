<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'ITV — Top 5000 TV Shows by IMDb')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/home.css') }}">
    @stack('styles')
</head>
<body class="@yield('body_class')">

@include('partials.navbar')

@yield('content')

<footer class="footer">
    <div class="container">Top 5000 TV Shows based on IMDb data.</div>
</footer>

<script>window.ITV_SHOWS = @json($shows ?? []);</script>
<script src="{{ asset('js/common.js') }}" defer></script>
<script src="{{ asset('js/nav.js') }}" defer></script>
@stack('scripts')
</body>
</html>