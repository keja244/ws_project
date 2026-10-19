<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Search — ITV</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/home.css') }}">
    <link rel="stylesheet" href="{{ asset('css/search.css') }}">
</head>
<body class="page-search">

<header class="nav">
    <div class="nav__inner">
        <a href="{{ url('/') }}" class="logo">ITV</a>
        <nav class="nav__links">
            <a href="{{ url('/') }}" class="nav__btn">Home</a>
            <a href="{{ url('/top-rated') }}" class="nav__btn">Top Rated</a>
            <a href="{{ url('/genre') }}" class="nav__btn">Genre</a>
        </nav>
    </div>
</header>

<main class="sp">
    <form class="search sp__bar" id="spForm" action="{{ url('/search') }}" method="GET" role="search">
        <input type="search" id="spInput" name="q" value="{{ request('q') }}" placeholder="Search by title, year, cast, or genre..." autocomplete="off" autofocus aria-label="Search TV series">
    </form>

    <p class="sp__status" id="spStatus" aria-live="polite"></p>

    <div class="sp__list" id="spList"></div>

    <div class="sp__more" id="spMore" hidden>
        <button type="button" id="spBtn">Show more</button>
    </div>
</main>

<footer class="footer">
    <div class="container">Top 5000 TV Shows based on IMDb data.</div>
</footer>

{{-- Backend: kirim $shows (array serial, key mengikuti field RDF + id). Kalau kosong, pakai placeholder. --}}
<script>window.ITV_SHOWS = @json($shows ?? []);</script>
<script src="{{ asset('js/search.js') }}" defer></script>
</body>
</html>