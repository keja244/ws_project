# Session Notes — Semantic Web Assignment Review

Date: 2025-11-26
Project: `D:\Kuliah\Sem 3\WS\Tubes\ws_project` (Laravel app, branch `main`)

## Context

Tugas besar web semantik. Required components:

- Aplikasi desktop/web (any language) — ✅ **done** (Laravel web app)
- File RDF — ❌ **missing** (dataset only lives inside Fuseki; no `.ttl` in repo)
- Laman RDFa — ❌ **missing** (no `vocab` / `typeof` / `property` attributes in any view)
- Open Graph Protocol — ❌ **missing** (no `og:` meta tags anywhere)
- Dataset buatan sendiri + SPARQL endpoint — ✅ **done** (Fuseki, `localhost:3030/WS_Project/sparql`, set via `FUSEKI_ENDPOINT` in `.env`)
- SPARQL endpoint eksternal — ✅ **done** (Wikidata `https://query.wikidata.org/sparql` via `SparqlService::queryWikidata()`; used by `ShowController::locationsApi()` line ~236 to fetch filming locations, plotted on Leaflet/OpenStreetMap map on detail page)
- Integrasi dengan layanan lain — ✅ **done** (OpenStreetMap + Leaflet on detail page; TMDB API via `TMDB_TOKEN`)

## Key files

- `routes/web.php` — all routes (search, genre, platform, keyword, company, person, top-rated, detail, api/locations, api/chat, api/chat/feedback)
- `app/Http/Controllers/ShowController.php` — 1481 lines; main controller; uses `SparqlService`
- `app/Services/SparqlService.php` — SPARQL client; `query()` for Fuseki, `queryWikidata()` for Wikidata
- `app/Services/ChatbotService.php` — 2222 lines; chatbot feature
- `config/services.php` — holds `fuseki.endpoint` + `wikidata` config
- `resources/views/detail.blade.php` — show detail page (has Leaflet, no RDFa yet)
- `resources/views/layouts/app.blade.php` — base layout (no OG tags yet)

## Next steps (agreed in conversation)

1. **Export RDF file**: dump the Fuseki dataset as `dataset.ttl` (via `CONSTRUCT { ?s ?p ?o } WHERE { ?s ?p ?o }`) and commit it to the repo.
2. **Add RDFa markup** to `resources/views/detail.blade.php`:
   - `<article vocab="https://schema.org/" typeof="TVSeries">` wrapper
   - `property="name"`, `property="description"`, `property="aggregateRating"`, etc. on real data elements
3. **Add Open Graph tags** to `resources/views/layouts/app.blade.php` (`og:title`, `og:image`, `og:description`) and on detail view.

## Open questions

- Which exact variables does `ShowController::detail()` pass to `detail.blade.php`? (not yet inspected — needed before writing RDFa on real fields)
- Does the grader require the RDF file to be checked in as a static `.ttl`, or generated at runtime?

## Notes for next chat

- Fuseki must be running at `localhost:3030` during any demo (endpoint is read locally, not in repo).
- TMDB token lives in `.env` (`TMDB_TOKEN`); fine, but `.env` is gitignored — do not commit it.
- Chatbot (`/api/chat`) exists and is throttled (12/min); feedback endpoint throttled 30/min.
