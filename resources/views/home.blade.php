<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ITV — Top 5000 TV Shows by IMDb</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Plus+Jakarta+Sans:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/home.css') }}">
</head>
<body>

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

<main>
    <section class="hero" id="home">
        <div class="container hero__grid">
            <div class="hero__text">
                <h1>Explore the World<br>of TV Series.</h1>
                <p>Explore thousands of TV series, discover new stories,<br>and find detailed information all in one place.</p>

                <form class="search search--suggest" action="{{ url('/search') }}" method="GET" role="search">
                <input type="search" id="heroInput" name="q" placeholder="Search TV series..." autocomplete="off" aria-label="Search TV series">
                <div class="suggest" id="heroSuggest" hidden></div>
            </form>
            </div>

            <div class="collage" id="collage" aria-hidden="true">
                <div class="collage__tile t1"></div>
                <div class="collage__tile t2"></div>
                <div class="collage__tile b1"></div>
                <div class="collage__tile b2"></div>
                <div class="collage__tile b3"></div>
            </div>
        </div>
    </section>

    <div class="container" id="rowsTop"></div>

    <section class="finder" id="search">
        <div class="container">
            <h2>Looking for something specific?</h2>
            <p>Explore thousands of TV shows by title, rating, or release year.</p>
            <div class="search search--lg">
                <input type="search" id="searchInput" placeholder="Search by title or year (e.g. 2008)" autocomplete="off" aria-label="Search TV series">
            </div>
            <p class="finder__status" id="searchStatus" aria-live="polite"></p>
            <div class="results" id="results"></div>
        </div>
    </section>

    <div class="container" id="rowsBottom"></div>
</main>

<footer class="footer">
    <div class="container">Top 5000 TV Shows based on IMDb data.</div>
</footer>

{{-- Opsional: kalau backend kirim $shows, dipakai di sini. Kalau kosong, JS pakai data contoh. --}}
<script>window.ITV_SHOWS = @json($shows ?? []);</script>
<script src="{{ asset('js/home.js') }}" defer></script>
</body>
</html>