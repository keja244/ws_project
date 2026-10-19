@php $isSearch = request()->is('search'); @endphp
<header class="nav">
    <div class="nav__inner">
        <a href="{{ url('/') }}" class="logo">ITV</a>

        <form class="search search--nav" id="navSearch" action="{{ url('/search') }}" method="GET" role="search" data-live="{{ $isSearch ? 1 : 0 }}">
            <input type="search" id="navInput" name="q" value="{{ $isSearch ? request('q') : '' }}" placeholder="Search TV series..." autocomplete="off" aria-label="Search TV series" {{ $isSearch ? 'autofocus' : '' }}>
            <button type="button" class="search__btn search__clear" id="navClear" aria-label="Clear search" hidden>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
            <button type="submit" class="search__btn" aria-label="Search">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            </button>
        </form>

        <nav class="nav__links">
            <a href="{{ url('/') }}" class="nav__btn {{ request()->is('/') ? 'is-active' : '' }}">Home</a>
            <a href="{{ url('/top-rated') }}" class="nav__btn {{ request()->is('top-rated') ? 'is-active' : '' }}">Top Rated</a>
            <a href="{{ url('/genre') }}" class="nav__btn {{ request()->is('genre') ? 'is-active' : '' }}">Genre</a>
        </nav>
    </div>
</header>