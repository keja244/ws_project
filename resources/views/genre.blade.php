<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Genre — ITV</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Plus+Jakarta+Sans:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/home.css') }}">
    <link rel="stylesheet" href="{{ asset('css/genre.css') }}">
</head>
<body>

<header class="nav">
    <div class="nav__inner">
        <a href="{{ url('/') }}" class="logo">ITV</a>
        <nav class="nav__links">
            <a href="{{ url('/') }}" class="nav__btn">Home</a>
            <a href="{{ url('/top-rated') }}" class="nav__btn">Top Rated</a>
            <a href="{{ url('/genre') }}" class="nav__btn is-active">Genre</a>
        </nav>

        <form class="search search--nav" action="{{ url('/search') }}" method="GET" role="search">
            <input type="search" name="q" placeholder="Search TV series..." autocomplete="off" aria-label="Search TV series">
        </form>
    </div>
</header>

<main>
    {{-- Judul halaman --}}
    <section class="page-head">
        <div class="container">
            <h1>Browse by Genre</h1>
            <p>Pick a genre and explore the best TV series in it.</p>
        </div>
    </section>

    {{-- Grid genre --}}
    <section class="container">
        <div class="genre-grid" id="genreGrid"></div>
    </section>

    {{-- Hasil per genre (muncul setelah genre dipilih) --}}
    <section class="genre-results" id="genreResults" hidden>
        <div class="container">
            <div class="genre-results__head">
                <div>
                    <h2 id="resultsTitle"></h2>
                    <p class="genre-results__status" id="resultsStatus" aria-live="polite"></p>
                </div>
                <label class="sort">
                    <span>Sort by</span>
                    <select id="sortSelect">
                        <option value="rating">Top Rated</option>
                        <option value="votes">Most Popular</option>
                        <option value="newest">Newest</option>
                        <option value="seasons">Most Seasons</option>
                    </select>
                </label>
            </div>

            <div class="results" id="genreShows"></div>

            <div class="more-wrap">
                <button type="button" class="more" id="moreBtn" hidden>Show more</button>
            </div>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="container">Top 5000 TV Shows based on IMDb data.</div>
</footer>

{{-- Opsional: kalau backend kirim $shows, dipakai di sini. Kalau kosong, JS pakai data contoh. --}}
<script>window.ITV_SHOWS = @json($shows ?? []);</script>
<script src="{{ asset('js/genre.js') }}" defer></script>
</body>
</html>