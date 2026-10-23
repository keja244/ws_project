@php
    $isSearch = request()->is('search');
    $isHome   = request()->is('/');
@endphp
<header class="nav {{ $isSearch ? 'is-searching' : '' }}">
    <div class="nav__inner">
        <div class="nav__left">
            <a href="{{ url('/') }}" class="logo">ITV</a>
            <a href="{{ url('/') }}" class="nav__btn {{ request()->is('/') ? 'is-active' : '' }}">Home</a>
        </div>

        {{-- Ikon search selalu ada, KECUALI di Home saat belum di-scroll.
         Klik ikon -> bar muncul. Klik isi bar -> pindah ke /search. Tombol X: hapus teks, kalau sudah kosong -> bar menutup. --}}
        <form class="search search--nav {{ $isSearch ? 'is-open' : ($isHome ? 'is-away' : '') }}" id="navSearch" action="{{ url('/search') }}" method="GET" role="search" data-live="{{ $isSearch ? 1 : 0 }}" data-home="{{ $isHome ? 1 : 0 }}">
            <input type="search" id="navInput" name="q" value="{{ $isSearch ? request('q') : '' }}" placeholder="Search TV series..." autocomplete="off" aria-label="Search TV series" {{ $isSearch ? (count(request()->query()) ? '' : 'autofocus') : 'tabindex=-1' }}>
            <button type="button" class="search__btn search__clear" id="navClear" aria-label="Clear search" hidden>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
            <button type="button" class="search__btn" id="navToggle" aria-label="Search" aria-expanded="{{ $isSearch ? 'true' : 'false' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            </button>
            {{-- Tombol filter: muncul begitu bar terbuka, di halaman mana pun (kanan search). Di /search membuka panel filter. --}}
            <button type="button" class="search__btn search__filter" id="navFilter" aria-label="Filters" title="Filters">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3Z"/></svg>
                <span class="search__badge" id="navFilterBadge" hidden></span>
            </button>
        </form>

        <nav class="nav__links">
            <a href="{{ url('/top-rated') }}" class="nav__btn {{ request()->is('top-rated') ? 'is-active' : '' }}">Top Rated</a>
            <a href="{{ url('/genre') }}" class="nav__btn {{ request()->is('genre') ? 'is-active' : '' }}">Genre</a>
            <a href="{{ url('/platform') }}" class="nav__btn {{ request()->is('platform') ? 'is-active' : '' }}">Platform</a>
        </nav>
    </div>
</header>