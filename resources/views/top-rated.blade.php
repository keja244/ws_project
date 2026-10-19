<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Top Rated — ITV</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/home.css') }}">
    <link rel="stylesheet" href="{{ asset('css/top-rated.css') }}">
</head>
<body>

<header class="nav">
    <div class="nav__inner nav__inner--detail">
        <a href="{{ url('/') }}" class="logo">ITV</a>
        <nav class="nav__links">
            <a href="{{ url('/') }}" class="nav__btn">Home</a>
            <a href="{{ url('/top-rated') }}" class="nav__btn is-active">Top Rated</a>
            <a href="{{ url('/genre') }}" class="nav__btn">Genre</a>
        </nav>

        <form class="search search--nav" action="{{ url('/search') }}" method="GET" role="search">
            <input type="search" name="q" placeholder="Search TV series..." autocomplete="off" aria-label="Search TV series">
        </form>
    </div>
</header>

<main class="container ranking">
    <div class="ranking__list" id="rankList"></div>
    <div class="ranking__more" id="rankMore">
        <p id="rankStatus"></p>
        <button type="button" id="rankBtn">Show more</button>
    </div>
</main>

<footer class="footer">
    <div class="container">Top 5000 TV Shows based on IMDb data.</div>
</footer>

{{-- Backend: kirim $shows (array serial, key mengikuti field RDF + id). Kalau kosong, pakai placeholder. --}}
<script>window.ITV_SHOWS = @json($shows ?? []);</script>
<script src="{{ asset('js/top-rated.js') }}" defer></script>
</body>
</html>