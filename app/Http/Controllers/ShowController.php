<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use App\Services\SparqlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShowController extends Controller
{
    // Subjek series di TTL: http://example.org/tvseries/series/tt0043208
    private const SERIES_NS = 'http://example.org/tvseries/series/';

    // Subjek person di TTL: http://example.org/tvseries/person/bryan-cranston
    private const PERSON_NS = 'http://example.org/tvseries/person/';

    // Subjek company di TTL: http://example.org/tvseries/organization/sony-pictures-television
    private const ORG_NS = 'http://example.org/tvseries/organization/';

    // Subjek genre di TTL: http://example.org/tvseries/genre/sci-fi
    private const GENRE_NS = 'http://example.org/tvseries/genre/';

    // Subjek platform di TTL: http://example.org/tvseries/platform/netflix
    // (BEDA dari company: organization:amc = produsen, platform:amc = penyiar)
    private const PLATFORM_NS = 'http://example.org/tvseries/platform/';

    // Subjek keyword di TTL: http://example.org/tvseries/keyword/new-mexico
    private const KEYWORD_NS = 'http://example.org/tvseries/keyword/';

    // Subjek country di TTL: http://example.org/tvseries/country/united-states
    private const COUNTRY_NS = 'http://example.org/tvseries/country/';

    // Subjek language di TTL: http://example.org/tvseries/language/english
    private const LANGUAGE_NS = 'http://example.org/tvseries/language/';

    // Field yang hanya dikirim ke browser kalau diminta lewat allShows([...])
    // _g / _a = daftar genre & aktor dalam bentuk array (khusus perhitungan similar & halaman person, tidak pernah dikirim)
    private const OPTIONAL = ['description', 'keywords', 'platform', 'author','productionCompany', 'numberOfEpisodes', 'contentRating', 'status', 'duration', '_g', '_a'];

    public function __construct(private SparqlService $sparql) {}

    // Fallback kalau node tidak punya rdfs:label:
    // ".../person/desi-arnaz" -> "Desi Arnaz"
    private static function label(string $uri): string
    {
        $base = urldecode(basename(rtrim($uri, '/')));
        return ucwords(str_replace(['_', '-'], ' ', $base));
    }

    // "Comedy|Drama" -> ['Comedy', 'Drama']
    private static function labels(?string $concat): array
    {
        if (!$concat) return [];
        $parts = array_filter(array_map('trim', explode('|', $concat)), 'strlen');
        return array_values(array_unique($parts));
    }

    // ISO 8601: "PT30M" -> 30, "PT1H" -> 60, "PT1H30M" -> 90, "49 min" -> 49
    private static function minutes(?string $v): int
    {
        if (!$v) return 0;
        if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?/i', $v, $m) && (!empty($m[1]) || !empty($m[2]))) {
            return ((int) ($m[1] ?? 0)) * 60 + (int) ($m[2] ?? 0);
        }
        return preg_match('/\d+/', $v, $m) ? (int) $m[0] : 0;
    }

    /**
     * Semua series (di-cache). Kolom multi-nilai yang besar (keywords, platform)
     * diambil lewat query terpisah supaya tidak terjadi cartesian product
     * genre x aktor x negara x keywords x platform.
     */
    private function loadShows(): array
    {
        return Cache::remember('all_shows_v10', 3600, function () {
            $rows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
                PREFIX ex: <http://example.org/tvseries/vocab#>
                SELECT ?s (SAMPLE(DISTINCT ?name) AS ?nameValue)
                    (SAMPLE(DISTINCT ?startDate) AS ?startDateValue)
                    (SAMPLE(DISTINCT ?endDate) AS ?endDateValue)
                    (SAMPLE(DISTINCT ?numberOfSeasons) AS ?numberOfSeasonsValue)
                    (SAMPLE(DISTINCT ?numberOfEpisodes) AS ?numberOfEpisodesValue)
                    (SAMPLE(DISTINCT ?contentRating) AS ?contentRatingValue)
                    (SAMPLE(DISTINCT ?status) AS ?statusValue)
                    (SAMPLE(DISTINCT ?duration) AS ?durationValue)
                    (GROUP_CONCAT(DISTINCT STR(?award); separator="|") AS ?awards)
                    (SAMPLE(DISTINCT ?image) AS ?imageValue)
                    (SAMPLE(DISTINCT ?description) AS ?descriptionValue)
                    (SAMPLE(DISTINCT ?rating) AS ?ratingValue)
                    (SAMPLE(DISTINCT ?votes) AS ?votesValue)
                    (GROUP_CONCAT(DISTINCT COALESCE(?gl, STR(?g)); separator="|") AS ?genres)
                    (GROUP_CONCAT(DISTINCT COALESCE(?al, STR(?a)); separator="|") AS ?actors)
                    (GROUP_CONCAT(DISTINCT COALESCE(?cl, REPLACE(REPLACE(STR(?c), "^.*[/#]", ""), "_", " ")); separator="|") AS ?countries)
                WHERE {
                    ?s a schema:TVSeries ; schema:name ?name .
                    OPTIONAL { ?s schema:startDate ?startDate . }
                    OPTIONAL { ?s schema:endDate ?endDate . }
                    OPTIONAL { ?s schema:numberOfSeasons ?numberOfSeasons . }
                    OPTIONAL { ?s schema:numberOfEpisodes ?numberOfEpisodes . }
                    OPTIONAL { ?s schema:contentRating ?contentRating . }
                    OPTIONAL { ?s ex:status ?status . }
                    OPTIONAL { ?s schema:duration ?duration . }
                    OPTIONAL { ?s schema:award ?award . }
                    OPTIONAL { ?s schema:image ?image . }
                    OPTIONAL { ?s schema:description ?description . }
                    OPTIONAL { ?s schema:genre ?g . OPTIONAL { ?g rdfs:label ?gl } }
                    OPTIONAL { ?s schema:actor ?a . OPTIONAL { ?a rdfs:label ?al } }
                    OPTIONAL { ?s schema:countryOfOrigin ?c . OPTIONAL { ?c rdfs:label ?cl } }
                    OPTIONAL {
                        ?s schema:aggregateRating ?r .
                        ?r schema:ratingValue ?rating .
                        OPTIONAL { ?r schema:ratingCount ?votes }
                    }
                }
                GROUP BY ?s
                ORDER BY DESC(xsd:decimal(?ratingValue))
            ');

            // keywords: satu baris per series (entitas ex:Keyword, ambil rdfs:label-nya)
            $kw = [];
            $kwRows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                SELECT ?s (GROUP_CONCAT(DISTINCT COALESCE(?kl, STR(?k)); separator="|") AS ?kw)
                WHERE {
                    ?s a schema:TVSeries ; schema:keywords ?k .
                    OPTIONAL { ?k rdfs:label ?kl }
                }
                GROUP BY ?s
            ');
            foreach ($kwRows as $r) {
                $kw[$r['s']] = array_map('mb_strtolower', self::labels($r['kw'] ?? null));
            }

            // platform: satu baris per series
            $pf = [];
            $pfRows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                PREFIX ex: <http://example.org/tvseries/vocab#>
                SELECT ?s (GROUP_CONCAT(DISTINCT COALESCE(?pl, STR(?p)); separator="|") AS ?platforms)
                WHERE {
                    ?s a schema:TVSeries ; ex:platform ?p .
                    OPTIONAL { ?p rdfs:label ?pl }
                }
                GROUP BY ?s
            ');
            foreach ($pfRows as $r) {
                $pf[$r['s']] = self::labels($r['platforms'] ?? null);
            }

            // author / creator ("Created by"): satu baris per series
            $au = [];
            $auRows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                SELECT ?s (GROUP_CONCAT(DISTINCT COALESCE(?al, STR(?a)); separator="|") AS ?authors)
                WHERE {
                    ?s a schema:TVSeries ; schema:author ?a .
                    OPTIONAL { ?a rdfs:label ?al }
                }
                GROUP BY ?s
            ');
            foreach ($auRows as $r) {
                $au[$r['s']] = self::labels($r['authors'] ?? null);
            }

            // production company: satu baris per (series, company).
            // Label dihitung di PHP dengan self::label() supaya sama persis dengan detail()
            // (kalau node company tidak punya rdfs:label, fallback dari URI).
            $pc = [];
            $pcRows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                SELECT DISTINCT ?s ?p ?pl
                WHERE {
                    ?s a schema:TVSeries ; schema:productionCompany ?p .
                    OPTIONAL { ?p rdfs:label ?pl }
                }
            ');
            foreach ($pcRows as $r) {
                $pc[$r['s']][] = !empty($r['pl']) ? $r['pl'] : self::label($r['p']);
            }

            return array_map(function ($r) use ($kw, $pf, $au, $pc) {
                $g = self::labels($r['genres'] ?? null);
                $a = self::labels($r['actors'] ?? null);
                return [
                    'id'               => basename(rtrim($r['s'], '/')),
                    'name'             => $r['nameValue'],
                    'aggregateRating'  => isset($r['ratingValue']) ? (float) $r['ratingValue'] : 0,
                    'interactionCount' => isset($r['votesValue']) ? (int) $r['votesValue'] : 0,
                    'startDate'        => $r['startDateValue'] ?? null,
                    'endDate'          => $r['endDateValue'] ?? null,
                    'numberOfSeasons'  => $r['numberOfSeasonsValue'] ?? null,
                    'numberOfEpisodes' => $r['numberOfEpisodesValue'] ?? null,
                    'contentRating'    => $r['contentRatingValue'] ?? '',
                    'status'           => $r['statusValue'] ?? '',
                    'duration'         => self::minutes($r['durationValue'] ?? null),
                    'award'            => implode(', ', self::labels($r['awards'] ?? null)),
                    'countryOfOrigin'  => implode(', ', self::labels($r['countries'] ?? null)),
                    'image'            => $r['imageValue'] ?? '',
                    'description'      => $r['descriptionValue'] ?? '',
                    'genre'            => implode(', ', $g),
                    'actor'            => implode(', ', $a),
                    'platform'         => $pf[$r['s']] ?? [],
                    'keywords'         => $kw[$r['s']] ?? [],
                    'author'           => $au[$r['s']] ?? [],
                    'productionCompany' => array_values(array_unique($pc[$r['s']] ?? [])),
                    '_g'               => $g,
                    '_a'               => $a,
                ];
            }, $rows);
        });
    }

    /** Versi ringan untuk dikirim ke browser; $with = field opsional yang ikut dikirim. */
    private function slim(array $shows, array $with = []): array
    {
        $drop = array_flip(array_diff(self::OPTIONAL, $with));
        return array_map(fn ($s) => array_diff_key($s, $drop), $shows);
    }

    private function allShows(array $with = []): array
    {
        return $this->slim($this->loadShows(), $with);
    }

    // Escape string dari input pengguna sebelum masuk ke query SPARQL
    private static function sparqlStr(string $v): string
    {
        return '"' . strtr($v, [
            '\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t',
        ]) . '"';
    }

    /**
     * Kartu series (bentuk sama dengan allShows() tanpa field OPTIONAL),
     * difilter oleh $pattern (graph pattern SPARQL yang memakai ?s).
     * $pattern HANYA boleh dari kode; nilai dari pengguna wajib lewat sparqlStr().
     */
    private function cardsWhere(string $pattern, string $order = 'DESC(xsd:decimal(?ratingValue)) DESC(xsd:integer(?votesValue))'): array
    {
        $rows = $this->sparql->query("
            PREFIX schema: <https://schema.org/>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
            SELECT ?s
                (SAMPLE(?name) AS ?nameValue)
                (SAMPLE(?startDate) AS ?startDateValue)
                (SAMPLE(?endDate) AS ?endDateValue)
                (SAMPLE(?numberOfSeasons) AS ?numberOfSeasonsValue)
                (SAMPLE(?image) AS ?imageValue)
                (SAMPLE(?rating) AS ?ratingValue)
                (SAMPLE(?votes) AS ?votesValue)
                (GROUP_CONCAT(DISTINCT STR(?award); separator='|') AS ?awards)
                (GROUP_CONCAT(DISTINCT COALESCE(?gl, STR(?g)); separator='|') AS ?genres)
                (GROUP_CONCAT(DISTINCT COALESCE(?al, STR(?a)); separator='|') AS ?actors)
                (GROUP_CONCAT(DISTINCT COALESCE(?cl, REPLACE(REPLACE(STR(?c), '^.*[/#]', ''), '_', ' ')); separator='|') AS ?countries)
            WHERE {
                ?s a schema:TVSeries ; schema:name ?name .
                $pattern
                OPTIONAL { ?s schema:startDate ?startDate . }
                OPTIONAL { ?s schema:endDate ?endDate . }
                OPTIONAL { ?s schema:numberOfSeasons ?numberOfSeasons . }
                OPTIONAL { ?s schema:award ?award . }
                OPTIONAL { ?s schema:image ?image . }
                OPTIONAL { ?s schema:genre ?g . OPTIONAL { ?g rdfs:label ?gl } }
                OPTIONAL { ?s schema:actor ?a . OPTIONAL { ?a rdfs:label ?al } }
                OPTIONAL { ?s schema:countryOfOrigin ?c . OPTIONAL { ?c rdfs:label ?cl } }
                OPTIONAL {
                    ?s schema:aggregateRating ?r .
                    ?r schema:ratingValue ?rating .
                    OPTIONAL { ?r schema:ratingCount ?votes }
                }
            }
            GROUP BY ?s
            ORDER BY $order
        ");

        return array_map(fn ($r) => [
            'id'               => basename(rtrim($r['s'], '/')),
            'name'             => $r['nameValue'],
            'aggregateRating'  => isset($r['ratingValue']) ? (float) $r['ratingValue'] : 0,
            'interactionCount' => isset($r['votesValue']) ? (int) $r['votesValue'] : 0,
            'startDate'        => $r['startDateValue'] ?? null,
            'endDate'          => $r['endDateValue'] ?? null,
            'numberOfSeasons'  => $r['numberOfSeasonsValue'] ?? null,
            'award'            => implode(', ', self::labels($r['awards'] ?? null)),
            'countryOfOrigin'  => implode(', ', self::labels($r['countries'] ?? null)),
            'image'            => $r['imageValue'] ?? '',
            'genre'            => implode(', ', self::labels($r['genres'] ?? null)),
            'actor'            => implode(', ', self::labels($r['actors'] ?? null)),
        ], $rows);
    }

    // ------------------------------------------------------------------
    // SEARCH & CATEGORY (SPARQL): pengganti filter/urut di JS (search-core.js, common.js).
    // Alurnya: (1) query ringan -> daftar IRI terurut, (2) PHP memotong per halaman,
    // (3) cardsWhere() mengambil data kartu untuk halaman itu saja.
    // ------------------------------------------------------------------

    // Fitur per series (satu baris per series). Hanya disertakan ke query kalau dibutuhkan.
    // ?gs / ?ps / ?ks = "|nama1|nama2|" huruf kecil, supaya cukup CONTAINS(?gs, "|drama|").
    private const FEAT_GENRE = '
        OPTIONAL { SELECT ?s (CONCAT("|", GROUP_CONCAT(DISTINCT LCASE(STR(COALESCE(?gl, REPLACE(STR(?g), "^.*[/#]", "")))); separator="|"), "|") AS ?gs)
                   WHERE { ?s schema:genre ?g . OPTIONAL { ?g rdfs:label ?gl } } GROUP BY ?s }
    ';
    private const FEAT_PLATFORM = '
        OPTIONAL { SELECT ?s (CONCAT("|", GROUP_CONCAT(DISTINCT LCASE(STR(COALESCE(?pl, REPLACE(STR(?p), "^.*[/#]", "")))); separator="|"), "|") AS ?ps)
                   WHERE { ?s ex:platform ?p . OPTIONAL { ?p rdfs:label ?pl } } GROUP BY ?s }
    ';
    private const FEAT_COUNTRY = '
        OPTIONAL { SELECT ?s (CONCAT("|", GROUP_CONCAT(DISTINCT LCASE(STR(COALESCE(?cl, REPLACE(REPLACE(STR(?c), "^.*[/#]", ""), "_", " ")))); separator="|"), "|") AS ?ks)
                   WHERE { ?s schema:countryOfOrigin ?c . OPTIONAL { ?c rdfs:label ?cl } } GROUP BY ?s }
    ';
    private const FEAT_CAST = '
        OPTIONAL { SELECT ?s (GROUP_CONCAT(DISTINCT LCASE(STR(COALESCE(?al, REPLACE(STR(?a), "^.*[/#]", "")))); separator=" ") AS ?csRaw)
                   WHERE { ?s schema:actor ?a . OPTIONAL { ?a rdfs:label ?al } } GROUP BY ?s }
    ';
    // ?wins = ex:winCount (angka terstruktur di TTL)
    private const FEAT_AWARDS = '
        OPTIONAL { ?s ex:winCount ?wins }
    ';
    // ?ctry = daftar negara dengan huruf asli, dipakai kategori "asian"
    private const FEAT_COUNTRY_RAW = '
        OPTIONAL { SELECT ?s (GROUP_CONCAT(DISTINCT STR(COALESCE(?cl, REPLACE(REPLACE(STR(?c), "^.*[/#]", ""), "_", " ")); separator=", ") AS ?ctry)
                   WHERE { ?s schema:countryOfOrigin ?c . OPTIONAL { ?c rdfs:label ?cl } } GROUP BY ?s }
    ';

    // Inti: nama, tahun tayang perdana (?yr), jumlah season (?ns), rating (?rt), votes (?votes)
    private const CORE = '
        { SELECT ?s (SAMPLE(?n) AS ?name) (MIN(?y0) AS ?yr) (MAX(?ns0) AS ?ns) (SAMPLE(?r0) AS ?rt) (SAMPLE(?v0) AS ?votes)
          WHERE {
            ?s a schema:TVSeries ; schema:name ?n .
            OPTIONAL { ?s schema:startDate ?sd . BIND(xsd:integer(SUBSTR(STR(?sd), 1, 4)) AS ?y0) }
            OPTIONAL { ?s schema:numberOfSeasons ?nsr . BIND(xsd:integer(?nsr) AS ?ns0) }
            OPTIONAL {
                ?s schema:aggregateRating ?ar . ?ar schema:ratingValue ?rr .
                BIND(xsd:decimal(?rr) AS ?r0)
                OPTIONAL { ?ar schema:ratingCount ?vc . BIND(xsd:integer(?vc) AS ?v0) }
            }
          } GROUP BY ?s }
    ';

    // Sama dengan ASIA di common.js
    private const ASIA = ['Japan', 'South Korea', 'Korea', 'China', 'India', 'Thailand', 'Taiwan', 'Hong Kong', 'Philippines', 'Indonesia', 'Singapore', 'Malaysia', 'Vietnam'];

    // Sama dengan norm() di search-core.js: buang aksen + huruf kecil
    private static function normText(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if ($d !== false) $s = preg_replace('/\p{Mn}/u', '', $d) ?? $s;
        }
        return mb_strtolower($s);
    }

    // Sama dengan tokensOf() di search-core.js
    private static function searchTokens(string $q): array
    {
        $s = self::normText($q);
        $s = preg_replace('/(\d{4})\s*(?:-|–|—|to|until|sampai)\s*(\d{4})/u', '$1~$2', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9+~ ]+/', ' ', $s) ?? $s;
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
            if ($w !== '' && (strlen($w) > 1 || preg_match('/\d/', $w))) $out[] = $w;
        }
        return $out;
    }

    // Sama dengan yearHit() di search-core.js, tapi menghasilkan [bawah, atas, skor] (dihitung di PHP, bukan di SPARQL)
    private static function yearRange(string $tok): ?array
    {
        if (preg_match('/^\d{4}$/', $tok)) return [(int) $tok, (int) $tok, 3];
        if (preg_match('/^(\d{4})~(\d{4})$/', $tok, $m)) return [(int) $m[1], (int) $m[2], 3];
        if (preg_match('/^(\d{4})s$/', $tok, $m)) return [(int) $m[1], (int) $m[1] + 9, 3];
        if (preg_match('/^(\d)0s$/', $tok, $m)) {
            $d = (int) $m[1];
            $b = $d >= 3 ? 1900 + $d * 10 : 2000 + $d * 10;   // "90s" -> 1990, "10s" -> 2010
            return [$b, $b + 9, 3];
        }
        if (preg_match('/^\d{3}$/', $tok)) return [(int) $tok * 10, (int) $tok * 10 + 9, 2];
        return null;
    }

    // Parameter dari query string (format sama dengan paramsToQuery() di search-core.js)
    private function searchParams(Request $request): array
    {
        $str  = function (string $k) use ($request): string {
            $v = $request->query($k);
            return is_string($v) ? $v : '';
        };
        $list = fn (string $k) => array_slice(array_values(array_filter(array_map('trim', explode('|', $str($k))), 'strlen')), 0, 20);
        $num  = fn (string $k) => is_numeric($str($k)) ? (float) $str($k) : null;

        $in   = $str('in');
        $sort = $str('sort');

        return [
            'q'        => mb_substr(trim($str('q')), 0, 200),
            'in'       => in_array($in, ['all', 'title', 'genre', 'year', 'cast'], true) ? $in : 'all',
            'genres'   => $list('genre'),
            'from'     => $num('from'),
            'to'       => $num('to'),
            'rating'   => $num('rating'),
            'seasons'  => $num('seasons'),
            'smax'     => $num('smax'),
            'platform' => $list('platform'),
            'country'  => $list('country'),
            'awards'   => $str('awards') === '1',
            'sort'     => in_array($sort, ['relevance', 'rating', 'votes', 'newest', 'oldest', 'seasons'], true) ? $sort : '',
        ];
    }

    /**
     * Susun query pencarian dari parameter. Return [fitur+BIND, FILTER, ORDER BY].
     * Skor relevansi meniru scoreToken()/scoreQuery() di search-core.js:
     * judul 6/5/3, genre 4/2, cast 3/2, tahun 3/2, platform & negara 1.5 (hanya scope "all"),
     * semua token wajib cocok, + bonus frasa (judul +6, cast +4).
     */
    private function searchPlan(array $p): array
    {
        $toks = self::searchTokens($p['q']);
        $in   = $p['in'];
        $all  = $in === 'all';

        $useTitle = $toks && ($all || $in === 'title');
        $useGenre = $toks && ($all || $in === 'genre');
        $useCast  = $toks && ($all || $in === 'cast');
        $useYear  = $toks && ($all || $in === 'year');
        $useMeta  = $toks && $all;   // platform & negara

        // 1) fitur yang benar-benar dibutuhkan
        $f = '';
        if ($p['genres'] || $useGenre)     $f .= self::FEAT_GENRE;
        if ($p['platform'] || $useMeta)    $f .= self::FEAT_PLATFORM;
        if ($p['country'] || $useMeta)     $f .= self::FEAT_COUNTRY;
        if ($useCast)                      $f .= self::FEAT_CAST;
        if ($p['awards'])                  $f .= self::FEAT_AWARDS;

        // 2) skor relevansi per token
        $binds = '';
        $conds = [];
        $terms = [];
        if ($toks) {
            $binds .= 'BIND(REPLACE(LCASE(STR(?name)), "[^a-z0-9+ ]+", " ") AS ?tl)' . "\n";
            if ($useGenre) $binds .= 'BIND(REPLACE(COALESCE(?gs, ""), "[^a-z0-9+| ]+", " ") AS ?gc)' . "\n";
            if ($useCast)  $binds .= 'BIND(CONCAT(" ", REPLACE(COALESCE(?csRaw, ""), "[^a-z0-9+ ]+", " ")) AS ?cc)' . "\n";

            foreach ($toks as $i => $tok) {
                $T = self::sparqlStr($tok);
                $c = [];
                if ($useTitle) {
                    $binds .= "BIND(IF(STRSTARTS(?tl, $T), 6, IF(CONTAINS(CONCAT(' ', ?tl), CONCAT(' ', $T)), 5, IF(CONTAINS(?tl, $T), 3, 0))) AS ?ti{$i})\n";
                    $c[] = "?ti{$i}";
                }
                if ($useGenre) {
                    $binds .= "BIND(IF(CONTAINS(?gc, CONCAT('|', $T)), 4, IF(CONTAINS(?gc, $T), 2, 0)) AS ?tg{$i})\n";
                    $c[] = "?tg{$i}";
                }
                if ($useCast) {
                    $binds .= "BIND(IF(CONTAINS(?cc, CONCAT(' ', $T)), 3, IF(CONTAINS(?cc, $T), 2, 0)) AS ?tc{$i})\n";
                    $c[] = "?tc{$i}";
                }
                if ($useYear && ($yr = self::yearRange($tok))) {
                    [$lo, $hi, $sc] = $yr;
                    $binds .= "BIND(IF(BOUND(?yr) && ?yr >= $lo && ?yr <= $hi, $sc, 0) AS ?ty{$i})\n";
                    $c[] = "?ty{$i}";
                }
                if ($useMeta) {
                    $binds .= "BIND(IF(CONTAINS(COALESCE(?ps, ''), $T), 1.5, 0) AS ?tp{$i})\n";
                    $binds .= "BIND(IF(CONTAINS(COALESCE(?ks, ''), $T), 1.5, 0) AS ?tk{$i})\n";
                    $c[] = "?tp{$i}";
                    $c[] = "?tk{$i}";
                }
                if (!$c) $c[] = '0'; 

                $cur = array_shift($c);
                foreach ($c as $j => $v) {
                    $binds .= "BIND(IF($cur > $v, $cur, $v) AS ?m{$i}_{$j})\n";
                    $cur = "?m{$i}_{$j}";
                }
                $terms[] = $cur;
                $conds[] = "$cur > 0";
            }

            if (count($toks) > 1) {
                $ph = self::sparqlStr(implode(' ', $toks));
                if ($useTitle) $terms[] = "IF(CONTAINS(?tl, $ph), 6, 0)";
                if ($useCast)  $terms[] = "IF(CONTAINS(?cc, $ph), 4, 0)";
            }
            $binds .= 'BIND(' . implode(' + ', $terms) . " AS ?score)\n";
        }

        // 3) filter
        $filters = [];
        if ($conds) $filters[] = '(' . implode(' && ', $conds) . ')';
        foreach ($p['genres'] as $g) {
            $filters[] = 'CONTAINS(COALESCE(?gs, ""), ' . self::sparqlStr('|' . mb_strtolower($g) . '|') . ')';
        }
        if ($p['from'] !== null)    $filters[] = '(BOUND(?yr) && ?yr >= ' . (int) $p['from'] . ')';
        if ($p['to'] !== null)      $filters[] = '(BOUND(?yr) && ?yr <= ' . (int) $p['to'] . ')';
        if ($p['rating'] !== null)  $filters[] = '(COALESCE(?rt, 0) >= ' . sprintf('%F', $p['rating']) . ')';
        if ($p['seasons'] !== null) $filters[] = '(COALESCE(?ns, 0) >= ' . (int) $p['seasons'] . ')';
        if ($p['smax'] !== null)    $filters[] = '(COALESCE(?ns, 0) > 0 && ?ns <= ' . (int) $p['smax'] . ')';
        if ($p['platform']) {
            $filters[] = '(' . implode(' || ', array_map(
                fn ($v) => 'CONTAINS(COALESCE(?ps, ""), ' . self::sparqlStr('|' . mb_strtolower($v) . '|') . ')',
                $p['platform']
            )) . ')';
        }
        if ($p['country']) {
            $filters[] = '(' . implode(' || ', array_map(
                fn ($v) => 'CONTAINS(COALESCE(?ks, ""), ' . self::sparqlStr('|' . mb_strtolower($v) . '|') . ')',
                $p['country']
            )) . ')';
        }
        if ($p['awards']) $filters[] = '(COALESCE(?wins, 0) > 0)';

        // 4) urutan (ASC(STR(?s)) = tie-breaker supaya halaman tidak saling tumpang tindih)
        $byRating = 'DESC(COALESCE(?rt, 0)) DESC(COALESCE(?votes, 0))';
        $sort = $p['sort'] ?: ($toks ? 'relevance' : 'rating');
        $order = match ($sort) {
            'relevance' => $toks ? 'DESC(?score) DESC(COALESCE(?votes, 0))' : $byRating,
            'votes'     => 'DESC(COALESCE(?votes, 0)) DESC(COALESCE(?rt, 0))',
            'newest'    => 'DESC(COALESCE(?yr, 0)) ' . $byRating,
            'oldest'    => 'ASC(COALESCE(?yr, 9999)) ' . $byRating,
            'seasons'   => 'DESC(COALESCE(?ns, 0)) ' . $byRating,
            default     => $byRating,
        };

        return [$f . $binds, implode(' && ', $filters), $order . ' ASC(STR(?s))'];
    }

    /** IRI series yang lolos filter, terurut. $features/$filters/$order dari kode, bukan dari input mentah. */
    private function rankedIris(string $features, string $filters, string $order, ?int $limit = null): array
    {
        $rows = $this->sparql->query("
            PREFIX schema: <https://schema.org/>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            PREFIX ex: <http://example.org/tvseries/vocab#>
            PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
            SELECT ?s WHERE {
                " . self::CORE . "
                $features
                " . ($filters !== '' ? "FILTER($filters)" : '') . "
            }
            ORDER BY $order
            " . ($limit ? 'LIMIT ' . (int) $limit : '') . "
        ");

        return array_column($rows, 's');
    }

    // Kartu untuk daftar IRI, urutan sesuai $iris
    private function cardsByIris(array $iris): array
    {
        if (!$iris) return [];
        $values = implode(' ', array_map(fn ($i) => '<' . $i . '>', $iris));
        $byId   = array_column($this->cardsWhere("VALUES ?s { $values }"), null, 'id');

        $out = [];
        foreach ($iris as $i) {
            $id = basename(rtrim($i, '/'));
            if (isset($byId[$id])) $out[] = $byId[$id];
        }
        return $out;
    }

    // Potong per halaman + ambil kartunya. ?page=N&per=M
    private function pageOf(array $iris, Request $request, int $defaultPer = 21): array
    {
        $per   = min(max((int) $request->query('per', $defaultPer), 1), 50);
        $total = count($iris);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min(max((int) $request->query('page', 1), 1), $pages);

        return [
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'shows' => $this->cardsByIris(array_slice($iris, ($page - 1) * $per, $per)),
        ];
    }

    /**
     * Facet panel filter (genre, platform, negara, rentang tahun). Kecil, di-cache.
     * Dikirim ke halaman search lewat window.ITV_FACETS.
     */
    private function searchFacets(): array
    {
        return Cache::remember('search_facets_v1', 3600, function () {
            $count = function (string $pattern, string $label): array {
                $rows = $this->sparql->query("
                    PREFIX schema: <https://schema.org/>
                    PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                    PREFIX ex: <http://example.org/tvseries/vocab#>
                    SELECT ?name (COUNT(DISTINCT ?s) AS ?n)
                    WHERE { ?s a schema:TVSeries . $pattern BIND($label AS ?name) }
                    GROUP BY ?name
                    ORDER BY DESC(?n) ASC(?name)
                ");
                return array_map(fn ($r) => ['name' => $r['name'], 'n' => (int) $r['n']], $rows);
            };

            $years = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
                SELECT (MIN(?y) AS ?mn) (MAX(?y) AS ?mx)
                WHERE {
                    ?s a schema:TVSeries ; schema:startDate ?sd .
                    BIND(xsd:integer(SUBSTR(STR(?sd), 1, 4)) AS ?y)
                }
            ');

            return [
                'genres'    => $count('?s schema:genre ?g . OPTIONAL { ?g rdfs:label ?gl }', 'STR(COALESCE(?gl, REPLACE(STR(?g), "^.*[/#]", "")))'),
                'platforms' => $count('?s ex:platform ?p . OPTIONAL { ?p rdfs:label ?pl }', 'STR(COALESCE(?pl, REPLACE(STR(?p), "^.*[/#]", "")))'),
                'countries' => $count('?s schema:countryOfOrigin ?c . OPTIONAL { ?c rdfs:label ?cl }', 'STR(COALESCE(?cl, REPLACE(REPLACE(STR(?c), "^.*[/#]", ""), "_", " ")))'),
                'minYear'   => !empty($years[0]['mn']) ? (int) $years[0]['mn'] : 1950,
                'maxYear'   => !empty($years[0]['mx']) ? (int) $years[0]['mx'] : (int) date('Y'),
            ];
        });
    }

    // /api/search?q=bryan&in=cast&genre=Drama|Crime&from=1990&rating=8&sort=rating&page=2&per=21
    // -> {total, page, pages, shows:[kartu]}. Dipakai halaman /search (hasil per=21, dropdown saran per=7).
    public function searchApi(Request $request)
    {
        $p = $this->searchParams($request);
        [$features, $filters, $order] = $this->searchPlan($p);

        $iris = Cache::remember(
            'search_ids_v1_' . md5($features . '|' . $filters . '|' . $order),
            600,
            fn () => $this->rankedIris($features, $filters, $order)
        );

        return response()->json($this->pageOf($iris, $request));
    }

    // /api/category/popular?page=2 -> {total, page, pages, shows:[kartu]}
    public function categoryShows(Request $request, string $slug)
    {
        abort_unless(in_array($slug, ['top-picks', 'popular', 'long-running', 'awards', 'asian'], true), 404);

        $iris = Cache::remember('category_ids_v1_' . $slug, 3600, function () use ($slug) {
            $byRating = 'DESC(COALESCE(?rt, 0)) DESC(COALESCE(?votes, 0))';
            $tie      = ' ASC(STR(?s))';

            switch ($slug) {
                case 'top-picks':     // 10 rating tertinggi
                    return $this->rankedIris('', '', $byRating . $tie, 10);
                case 'popular':       // votes terbanyak
                    return $this->rankedIris('', '', 'DESC(COALESCE(?votes, 0)) DESC(COALESCE(?rt, 0))' . $tie);
                case 'long-running':  // minimal 2 season, season terbanyak dulu
                    return $this->rankedIris('', 'COALESCE(?ns, 0) >= 2', 'DESC(COALESCE(?ns, 0)) ' . $byRating . $tie);
                case 'awards':        // pernah menang, jumlah "wins" terbanyak dulu
                    return $this->rankedIris(self::FEAT_AWARDS, 'COALESCE(?wins, 0) > 0', 'DESC(COALESCE(?wins, 0)) ' . $byRating . $tie);
                default:              // asian: negara mengandung salah satu nama di ASIA
                    $or = implode(' || ', array_map(
                        fn ($c) => 'CONTAINS(COALESCE(?ctry, ""), ' . self::sparqlStr($c) . ')',
                        self::ASIA
                    ));
                    return $this->rankedIris(self::FEAT_COUNTRY_RAW, "($or)", $byRating . $tie);
            }
        });

        return response()->json($this->pageOf($iris, $request));
    }

    /**
     * Rank sama persis dengan top-rated.js:
     * Weighted Rating (Bayesian) -> rating desc -> votes desc -> nama A-Z.
     * Return: ['tt0903747' => 1, 'tt0944947' => 2, ...]
     */
    private function ranks(): array
    {
        return Cache::remember('show_ranks_v1', 3600, function () {
            $rated = array_values(array_filter(
                $this->loadShows(),
                fn ($s) => $s['aggregateRating'] > 0
            ));
            if (!$rated) return [];

            $n = count($rated);

            // C = rata-rata rating seluruh series
            $C = array_sum(array_column($rated, 'aggregateRating')) / $n;

            // M = persentil ke-75 dari jumlah vote (minimal 1)
            $votes = array_column($rated, 'interactionCount');
            sort($votes);
            $M = $votes[(int) floor($n * 0.75)] ?? 1;
            $M = $M ?: 1;

            $score = function (array $s) use ($C, $M) {
                $v = $s['interactionCount'];
                return ($v / ($v + $M)) * $s['aggregateRating'] + ($M / ($v + $M)) * $C;
            };

            foreach ($rated as &$s) {
                $s['_score'] = $score($s);
            }
            unset($s);

            $collator = class_exists(\Collator::class) ? new \Collator('en_US') : null;

            usort($rated, function ($a, $b) use ($collator) {
                return ($b['_score'] <=> $a['_score'])
                    ?: ($b['aggregateRating'] <=> $a['aggregateRating'])
                    ?: ($b['interactionCount'] <=> $a['interactionCount'])
                    ?: ($collator
                        ? $collator->compare((string) $a['name'], (string) $b['name'])
                        : strcasecmp((string) $a['name'], (string) $b['name']));
            });

            $map = [];
            foreach ($rated as $i => $s) {
                $map[$s['id']] = $i + 1;
            }
            return $map;
        });
    }

    /**
     * Rekomendasi mirip: skor gabungan genre (Jaccard), keywords (cosine), cast (maks 3 orang).
     * Series yang tidak berbagi apa pun dengan series ini tidak ikut.
     * Skor dihitung di SPARQL (butuh Jena/Fuseki: afn:sqrt), lalu kartu 12 teratas diambil lewat cardsWhere().
     */
    private function similarTo(string $id, int $limit = 12): array
    {
        $uri = self::SERIES_NS . $id;

        // + rating kecil sebagai tie-breaker (?rt / 100)
        $rows = $this->sparql->query("
            PREFIX schema: <https://schema.org/>
            PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
            PREFIX afn: <http://jena.apache.org/ARQ/function#>
            SELECT ?s ((2*?genreSim + 3*?kwSim + 2*?castSim + ?rt/100) AS ?score)
            WHERE {
                ?s a schema:TVSeries .
                FILTER(?s != <$uri>)

                { SELECT (COUNT(DISTINCT ?g0) AS ?nG) WHERE { <$uri> schema:genre ?g0 } }
                { SELECT (COUNT(DISTINCT ?k0) AS ?nK) WHERE { <$uri> schema:keywords ?k0 } }

                OPTIONAL { SELECT ?s (COUNT(DISTINCT ?g1) AS ?gS) WHERE {
                    <$uri> schema:genre ?g1 . ?s schema:genre ?g1 . FILTER(?s != <$uri>) } GROUP BY ?s }
                OPTIONAL { SELECT ?s (COUNT(DISTINCT ?g2) AS ?gT) WHERE {
                    ?s schema:genre ?g2 } GROUP BY ?s }
                OPTIONAL { SELECT ?s (COUNT(DISTINCT ?k1) AS ?kS) WHERE {
                    <$uri> schema:keywords ?k1 . ?s schema:keywords ?k1 . FILTER(?s != <$uri>) } GROUP BY ?s }
                OPTIONAL { SELECT ?s (COUNT(DISTINCT ?k2) AS ?kT) WHERE {
                    ?s schema:keywords ?k2 } GROUP BY ?s }
                OPTIONAL { SELECT ?s (COUNT(DISTINCT ?a1) AS ?aS) WHERE {
                    <$uri> schema:actor ?a1 . ?s schema:actor ?a1 . FILTER(?s != <$uri>) } GROUP BY ?s }
                OPTIONAL { ?s schema:aggregateRating/schema:ratingValue ?ratingRaw }

                BIND(COALESCE(?gS, 0) AS ?gc)
                BIND(COALESCE(?kS, 0) AS ?kc)
                BIND(COALESCE(?aS, 0) AS ?ac)
                FILTER(?gc + ?kc + ?ac > 0)

                BIND(?nG + COALESCE(?gT, 0) - ?gc AS ?uni)
                BIND(IF(?uni > 0, ?gc / ?uni, 0) AS ?genreSim)
                BIND(afn:sqrt(?nK * IF(COALESCE(?kT, 0) > 1, ?kT, 1)) AS ?kwDen)
                BIND(IF(?kc > 0 && ?kwDen > 0, ?kc / ?kwDen, 0) AS ?kwSim)
                BIND(IF(?ac > 3, 3, ?ac) / 3 AS ?castSim)
                BIND(COALESCE(xsd:decimal(?ratingRaw), 0) AS ?rt)
            }
            ORDER BY DESC(?score)
            LIMIT " . (int) $limit . "
        ");

        if (!$rows) return [];

        $ids    = array_map(fn ($r) => basename(rtrim($r['s'], '/')), $rows);
        $values = implode(' ', array_map(fn ($r) => '<' . $r['s'] . '>', $rows));
        $cards  = $this->cardsWhere("VALUES ?s { $values }");

        $byId = array_column($cards, null, 'id');
        return array_values(array_filter(array_map(fn ($i) => $byId[$i] ?? null, $ids)));
    }

    public function home()
    {
        return view('home', ['shows' => $this->allShows()]);
    }

    public function search()
    {
        // 'platform' ikut dikirim supaya filter Platform di halaman search bisa bekerja
        return view('search', ['shows' => [], 'facets' => $this->searchFacets()]);
    }

    public function chat(Request $request, ChatbotService $chatbot)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'conversationId' => ['nullable', 'uuid'],
        ]);

        $conversationId = $validated['conversationId'] ?? 'legacy';
        $stateKey = 'series_tv_chat_state_'.hash('sha256', strtolower($conversationId));
        $activityKey = $stateKey.'_last_activity';
        $lastActivity = $request->session()->get($activityKey);
        if (! is_numeric($lastActivity) || now()->timestamp - (int) $lastActivity >= 1800) {
            $request->session()->forget([$stateKey, $activityKey]);
        }
        $response = $chatbot->respond(
            $validated['message'],
            $this->loadShows(),
            (array) $request->session()->get($stateKey, [])
        );
        $request->session()->put($activityKey, now()->timestamp);
        if (array_key_exists('state', $response)) {
            $request->session()->put($stateKey, $response['state']);
            unset($response['state']);
        }

        $response['responseId'] = (string) Str::uuid();
        if (! empty($response['failedQuery'])) {
            $query = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $validated['message']) ?? $validated['message'];
            $query = preg_replace('/(?<!\d)\+?\d[\d\s().-]{7,}\d(?!\d)/u', '[number]', $query) ?? $query;
            Log::info('chatbot.failed_query', [
                'session' => hash_hmac('sha256', $request->session()->getId(), (string) config('app.key')),
                'query' => mb_substr($query, 0, 500),
            ]);
            unset($response['failedQuery']);
        }

        return response()->json($response);
    }

    public function chatFeedback(Request $request)
    {
        $validated = $request->validate([
            'responseId' => ['required', 'uuid'],
            'helpful' => ['required', 'boolean'],
            'reason' => ['nullable', 'in:irrelevant,metadata,misunderstood'],
        ]);
        $helpful = filter_var($validated['helpful'], FILTER_VALIDATE_BOOLEAN);
        if (! $helpful && empty($validated['reason'])) {
            return response()->json(['message' => 'Pilih alasan singkat untuk feedback ini.'], 422);
        }

        Log::info('chatbot.feedback', [
            'session' => hash_hmac('sha256', $request->session()->getId(), (string) config('app.key')),
            'response_id' => $validated['responseId'],
            'helpful' => $helpful,
            'reason' => $helpful ? null : $validated['reason'],
        ]);

        return response()->json(['message' => 'Terima kasih atas feedback-mu.']);
    }

    /**
     * Tile genre: satu entitas ex:Genre per tile (IRI -> slug), jumlah series lewat schema:genre,
     * cover = series ber-rating tertinggi yang punya gambar. Hasil kecil, di-cache.
     */
    private function genreTiles(): array
    {
        return Cache::remember('genre_tiles_v1', 3600, function () {
            $rows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                PREFIX ex: <http://example.org/tvseries/vocab#>
                SELECT ?g (SAMPLE(?label) AS ?labelValue) (COUNT(DISTINCT ?s) AS ?n)
                WHERE {
                    ?g a ex:Genre ; rdfs:label ?label .
                    ?s a schema:TVSeries ; schema:genre ?g .
                }
                GROUP BY ?g
            ');

            // urut dari rating tertinggi: baris pertama per genre = cover-nya
            $coverRows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
                SELECT ?g ?image
                WHERE {
                    ?s a schema:TVSeries ; schema:genre ?g ; schema:image ?image ;
                       schema:aggregateRating ?r .
                    ?r schema:ratingValue ?rating .
                    OPTIONAL { ?r schema:ratingCount ?votes . }
                }
                ORDER BY DESC(xsd:decimal(?rating)) DESC(xsd:integer(?votes))
            ');
            $covers = [];
            foreach ($coverRows as $r) {
                $covers[$r['g']] ??= $r['image'];
            }

            $tiles = array_map(fn ($r) => [
                'slug'  => basename(rtrim($r['g'], '/')),
                'name'  => $r['labelValue'],
                'count' => (int) $r['n'],
                'cover' => $covers[$r['g']] ?? '',
            ], $rows);

            usort($tiles, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
            return $tiles;
        });
    }

    // /genre            -> daftar tile
    // /genre/{slug}     -> daftar tile + hasil genre itu (dimuat browser lewat /api/genre/{slug})
    public function genre(Request $request, ?string $slug = null)
    {
        $tiles = $this->genreTiles();

        if ($slug === null) {
            $legacy = trim((string) $request->query('genre', ''));
            if ($legacy !== '') {
                foreach ($tiles as $t) {
                    if (mb_strtolower($t['name']) === mb_strtolower($legacy)) {
                        $rest = $request->except('genre');
                        return redirect()->to(
                            url('/genre/' . $t['slug']) . ($rest ? '?' . http_build_query($rest) : ''),
                            301
                        );
                    }
                }
            }
        } else {
            abort_unless(in_array($slug, array_column($tiles, 'slug'), true), 404);
        }

        return view('genre', ['tiles' => $tiles, 'initial' => $slug]);
    }

    // /api/genre/drama -> series yang terhubung ke IRI genre:drama lewat schema:genre (JSON, di-cache per genre)
    public function genreShows(string $slug)
    {
        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), 404);
        abort_unless(in_array($slug, array_column($this->genreTiles(), 'slug'), true), 404);

        $uri   = self::GENRE_NS . $slug;
        $shows = Cache::remember('genre_shows_v1_' . $slug, 3600, fn () => $this->cardsLinkedTo('schema:genre', $uri));

        return response()->json(['shows' => $shows]);
    }

    /**
     * Tile platform: satu entitas ex:Platform per tile (IRI -> slug), jumlah series lewat ex:platform,
     * cover = series ber-rating tertinggi yang punya gambar. Yang paling banyak series-nya di depan.
     */
    private function platformTiles(): array
    {
        return Cache::remember('platform_tiles_v1', 3600, function () {
            $rows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                PREFIX ex: <http://example.org/tvseries/vocab#>
                SELECT ?p (SAMPLE(?label) AS ?labelValue) (COUNT(DISTINCT ?s) AS ?n)
                WHERE {
                    ?p a ex:Platform ; rdfs:label ?label .
                    ?s a schema:TVSeries ; ex:platform ?p .
                }
                GROUP BY ?p
            ');

            // urut dari rating tertinggi: baris pertama per platform = cover-nya
            $coverRows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX ex: <http://example.org/tvseries/vocab#>
                PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
                SELECT ?p ?image
                WHERE {
                    ?s a schema:TVSeries ; ex:platform ?p ; schema:image ?image ;
                       schema:aggregateRating ?r .
                    ?r schema:ratingValue ?rating .
                    OPTIONAL { ?r schema:ratingCount ?votes . }
                }
                ORDER BY DESC(xsd:decimal(?rating)) DESC(xsd:integer(?votes))
            ');
            $covers = [];
            foreach ($coverRows as $r) {
                $covers[$r['p']] ??= $r['image'];
            }

            $tiles = array_map(fn ($r) => [
                'slug'  => basename(rtrim($r['p'], '/')),
                'name'  => $r['labelValue'],
                'count' => (int) $r['n'],
                'cover' => $covers[$r['p']] ?? '',
            ], $rows);

            usort($tiles, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcasecmp($a['name'], $b['name']));
            return $tiles;
        });
    }

    // /platform            -> daftar tile
    // /platform/{slug}     -> daftar tile + hasil platform itu (dimuat browser lewat /api/platform/{slug})
    public function platform(Request $request, ?string $slug = null)
    {
        $tiles = $this->platformTiles();

        if ($slug === null) {
            $legacy = trim((string) $request->query('platform', ''));
            if ($legacy !== '') {
                foreach ($tiles as $t) {
                    if (mb_strtolower($t['name']) === mb_strtolower($legacy)) {
                        $rest = $request->except('platform');
                        return redirect()->to(
                            url('/platform/' . $t['slug']) . ($rest ? '?' . http_build_query($rest) : ''),
                            301
                        );
                    }
                }
            }
        } else {
            abort_unless(in_array($slug, array_column($tiles, 'slug'), true), 404);
        }

        return view('platform', ['tiles' => $tiles, 'initial' => $slug]);
    }

    // /api/platform/netflix -> series yang terhubung ke IRI platform:netflix lewat ex:platform (JSON, di-cache per platform)
    public function platformShows(string $slug)
    {
        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), 404);
        abort_unless(in_array($slug, array_column($this->platformTiles(), 'slug'), true), 404);

        $uri   = self::PLATFORM_NS . $slug;
        $shows = Cache::remember('platform_shows_v1_' . $slug, 3600, fn () => $this->cardsLinkedTo('ex:platform', $uri));

        return response()->json(['shows' => $shows]);
    }

    public function topRated()
    {
        return view('top-rated', ['shows' => $this->allShows(['description'])]);
    }

    public function category(string $slug)
    {
        abort_unless(in_array($slug, ['top-picks', 'popular', 'long-running', 'awards', 'asian'], true), 404);
        // daftar series diambil browser lewat /api/category/{slug} (lihat categoryShows())
        return view('category', ['shows' => [], 'slug' => $slug]);
    }

    // /keyword/new-mexico   -> series yang terhubung ke IRI keyword:new-mexico lewat schema:keywords
    public function keyword(Request $request, ?string $slug = null)
    {
        if ($slug === null) {
            $k = mb_strtolower(trim((string) $request->query('k', '')));
            if ($k === '') {
                return view('keyword', ['shows' => [], 'keyword' => '']);
            }

            $rows = $this->sparql->query('
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                PREFIX ex: <http://example.org/tvseries/vocab#>
                SELECT ?k WHERE {
                    ?k a ex:Keyword ; rdfs:label ?l .
                    FILTER(LCASE(STR(?l)) = ' . self::sparqlStr($k) . ')
                } LIMIT 1
            ');
            if (!$rows) {
                return view('keyword', ['shows' => [], 'keyword' => $k]);
            }

            return redirect()->to(url('/keyword/' . basename(rtrim($rows[0]['k'], '/'))), 301);
        }

        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), 404);

        $uri   = self::KEYWORD_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, 404);

        $shows = Cache::remember(
            'keyword_shows_v1_' . $slug,
            3600,
            fn () => $this->cardsWhere("?s schema:keywords <$uri> .")
        );

        return view('keyword', ['shows' => $shows, 'keyword' => $label]);
    }

    /**
     * rdfs:label dari satu IRI entitas (null kalau IRI itu tidak ada di graf).
     * $iri harus dibangun dari namespace + slug yang sudah lolos regex.
     */
    private function labelOf(string $iri): ?string
    {
        $rows = $this->sparql->query("
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            SELECT ?label WHERE { <$iri> rdfs:label ?label . } LIMIT 1
        ");
        return $rows[0]['label'] ?? null;
    }

    /**
     * Series (field kartu saja) yang terhubung ke satu entitas lewat satu predikat:
     *   ?s {$predicate} <$iri>
     * Dipakai halaman company, genre (schema:genre), dan platform (ex:platform).
     * $predicate HANYA boleh dari kode (konstanta), bukan dari input pengguna.
     */
    private function cardsLinkedTo(string $predicate, string $iri): array
    {
        $rows = $this->sparql->query("
            PREFIX schema: <https://schema.org/>
            PREFIX ex: <http://example.org/tvseries/vocab#>
            PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
            SELECT ?s
                (SAMPLE(?name) AS ?nameValue)
                (SAMPLE(?startDate) AS ?startDateValue)
                (SAMPLE(?endDate) AS ?endDateValue)
                (SAMPLE(?image) AS ?imageValue)
                (SAMPLE(?rating) AS ?ratingValue)
                (SAMPLE(?votes) AS ?votesValue)
                (SAMPLE(?seasons) AS ?seasonsValue)
            WHERE {
                ?s $predicate <$iri> .
                ?s a schema:TVSeries ; schema:name ?name .
                OPTIONAL { ?s schema:startDate ?startDate . }
                OPTIONAL { ?s schema:endDate ?endDate . }
                OPTIONAL { ?s schema:image ?image . }
                OPTIONAL { ?s schema:numberOfSeasons ?seasons . }
                OPTIONAL {
                    ?s schema:aggregateRating ?r .
                    ?r schema:ratingValue ?rating .
                    OPTIONAL { ?r schema:ratingCount ?votes . }
                }
            }
            GROUP BY ?s
            ORDER BY DESC(xsd:decimal(?ratingValue)) DESC(xsd:integer(?votesValue))
        ");

        return array_map(fn ($r) => [
            'id'               => basename(rtrim($r['s'], '/')),
            'name'             => $r['nameValue'],
            'aggregateRating'  => isset($r['ratingValue']) ? (float) $r['ratingValue'] : 0,
            'interactionCount' => isset($r['votesValue']) ? (int) $r['votesValue'] : 0,
            'startDate'        => $r['startDateValue'] ?? null,
            'endDate'          => $r['endDateValue'] ?? null,
            'image'            => $r['imageValue'] ?? '',
            'numberOfSeasons'  => $r['seasonsValue'] ?? null,
        ], $rows);
    }

    // /company/sony-pictures-television -> series yang diproduksi company itu.
    // Dicari lewat IRI organization:{slug} di Fuseki
    public function company(string $slug)
    {
        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), 404);

        $uri   = self::ORG_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, 404);

        return view('company', [
            'shows'   => $this->cardsLinkedTo('schema:productionCompany', $uri),
            'company' => $label,
        ]);
    }

    // /country/united-states -> series yang berasal dari negara itu (schema:countryOfOrigin).
    // Dicari lewat IRI country:{slug} di Fuseki
    public function country(string $slug)
    {
        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), 404);

        $uri   = self::COUNTRY_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, 404);

        $shows = Cache::remember('country_shows_v1_' . $slug, 3600, fn () => $this->cardsLinkedTo('schema:countryOfOrigin', $uri));

        return view('country', ['shows' => $shows, 'country' => $label]);
    }

    // /language/english -> series yang memakai bahasa itu
    public function language(string $slug)
    {
        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), 404);

        $uri   = self::LANGUAGE_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, 404);

        $shows = Cache::remember('language_shows_v1_' . $slug, 3600, fn () => $this->cardsLinkedTo('ex:spokenLanguage', $uri));

        return view('language', ['shows' => $shows, 'language' => $label]);
    }

    // /person/bryan-cranston -> series yang dibintangi ATAU dibuat orang itu.
    // Dicari lewat IRI person:{slug} di Fuseki
    // 'roles' (actor / author) dikirim per series supaya halaman bisa menyaring per peran.
    public function person(string $slug)
    {
        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), 404);

        $uri = self::PERSON_NS . $slug;

        // 1) Orangnya ada? (sekaligus mengambil label untuk judul halaman)
        $who = $this->sparql->query("
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            SELECT ?label WHERE { <$uri> rdfs:label ?label . } LIMIT 1
        ");
        abort_if(empty($who), 404);

        // 2) Series dengan orang ini sebagai aktor dan/atau author, plus field kartu
        $rows = $this->sparql->query("
            PREFIX schema: <https://schema.org/>
            PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
            SELECT ?s
                (SAMPLE(?name) AS ?nameValue)
                (SAMPLE(?startDate) AS ?startDateValue)
                (SAMPLE(?endDate) AS ?endDateValue)
                (SAMPLE(?image) AS ?imageValue)
                (SAMPLE(?rating) AS ?ratingValue)
                (SAMPLE(?votes) AS ?votesValue)
                (GROUP_CONCAT(DISTINCT ?role; separator='|') AS ?roles)
            WHERE {
                { ?s schema:actor <$uri> . BIND('actor' AS ?role) }
                UNION
                { ?s schema:author <$uri> . BIND('author' AS ?role) }
                ?s a schema:TVSeries ; schema:name ?name .
                OPTIONAL { ?s schema:startDate ?startDate . }
                OPTIONAL { ?s schema:endDate ?endDate . }
                OPTIONAL { ?s schema:image ?image . }
                OPTIONAL {
                    ?s schema:aggregateRating ?r .
                    ?r schema:ratingValue ?rating .
                    OPTIONAL { ?r schema:ratingCount ?votes . }
                }
            }
            GROUP BY ?s
            ORDER BY DESC(xsd:decimal(?ratingValue)) DESC(xsd:integer(?votesValue))
        ");

        $shows = array_map(fn ($r) => [
            'id'               => basename(rtrim($r['s'], '/')),
            'name'             => $r['nameValue'],
            'aggregateRating'  => isset($r['ratingValue']) ? (float) $r['ratingValue'] : 0,
            'interactionCount' => isset($r['votesValue']) ? (int) $r['votesValue'] : 0,
            'startDate'        => $r['startDateValue'] ?? null,
            'endDate'          => $r['endDateValue'] ?? null,
            'image'            => $r['imageValue'] ?? '',
            'roles'            => self::labels($r['roles'] ?? null),
        ], $rows);

        return view('person', [
            'shows'  => $shows,
            'person' => $who[0]['label'],
        ]);
    }

    public function detail(string $id)
    {
        abort_unless(preg_match('/^tt\d+$/', $id), 404);

        $uri  = self::SERIES_NS . $id;  // subjek di triplestore
        $imdb = "https://www.imdb.com/title/{$id}/"; // untuk tombol "View on IMDb"

        // Semua properti series + label objek (genre, aktor, negara, bahasa, platform, dst.)
        $rows = $this->sparql->query("
            PREFIX schema: <https://schema.org/>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            SELECT ?p ?o ?ol WHERE {
                <$uri> ?p ?o .
                OPTIONAL { ?o rdfs:label ?ol . }
            }
        ");
        abort_if(empty($rows), 404);

        // Rating ada di node terpisah (.../tt0043208/rating)
        $ratingRows = $this->sparql->query("
            PREFIX schema: <https://schema.org/>
            SELECT ?rating ?votes ?best ?worst WHERE {
                <$uri> schema:aggregateRating ?r .
                ?r schema:ratingValue ?rating .
                OPTIONAL { ?r schema:ratingCount ?votes . }
                OPTIONAL { ?r schema:bestRating ?best . }
                OPTIONAL { ?r schema:worstRating ?worst . }
            } LIMIT 1
        ");

        // Hasil per kategori award (ex:AwardResult -> ex:Award), urut sesuai ex:sortOrder
        $awardRows = $this->sparql->query("
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            PREFIX ex: <http://example.org/tvseries/vocab#>
            SELECT ?label ?won ?nom ?ord WHERE {
                <$uri> ex:awardResult ?r .
                ?r ex:awardCategory ?a .
                ?a rdfs:label ?label .
                OPTIONAL { ?a ex:sortOrder ?ord }
                OPTIONAL { ?r ex:wonCount ?won }
                OPTIONAL { ?r ex:nominatedCount ?nom }
            }
            ORDER BY ?ord ?label
        ");

        // dikirim sebagai string "A, B, C" (dipecah di detail.js)
        $multiString = ['inLanguage'];
        // dikirim sebagai array (nama bisa mengandung koma)
        $multiArray  = [];
        // TIDAK di $multiString/$multiArray: dikirim terpisah sebagai [{slug, name}]
        // untuk link ke /person/{slug}, /company/{slug}, /genre/{slug}, /platform/{slug}, /keyword/{slug},
        // /country/{slug}, dan /language/{slug}
        $linkedKeys  = ['actor', 'author', 'productionCompany', 'genre', 'platform', 'keywords', 'countryOfOrigin', 'spokenLanguage'];
        $single = [
            'name', 'description', 'image', 'award', 'contentRating', 'slogan',
            'startDate', 'endDate', 'numberOfSeasons', 'numberOfEpisodes',
            'status', 'tmdbId', 'tvdbId', 'wikidataId', 'imdbId',
            'trailer', 'thumbnailUrl', 'alternateName',
            'winCount', 'nominationCount',
        ];

        $show  = ['id' => $id, 'uri' => $imdb];
        $lists = [];
        $linked = array_fill_keys($linkedKeys, []); 
        $links = [];

        foreach ($rows as $r) {
            $key = preg_replace('/^.*[\/#]/', '', $r['p']);
            $val = $r['o'];
            $lbl = !empty($r['ol']) ? $r['ol'] : self::label($val);

            if (in_array($key, $linkedKeys, true)) {
                $linked[$key][basename(rtrim($val, '/'))] = $lbl;
            } elseif (in_array($key, $multiString, true) || in_array($key, $multiArray, true)) {
                $lists[$key][] = $lbl;
            } elseif ($key === 'sameAs') { // owl:sameAs & schema:sameAs
                $links[] = $val;
            } elseif ($key === 'duration') {
                $show['duration'] = self::minutes($val);
            } elseif (in_array($key, $single, true)) {
                $show[$key] = $val;
            }
        }

        foreach ($multiString as $k) {
            if (isset($lists[$k])) $show[$k] = implode(', ', array_unique($lists[$k]));
        }
        foreach ($multiArray as $k) {
            $show[$k] = array_values(array_unique($lists[$k] ?? []));
        }
        foreach ($linkedKeys as $k) {
            $show[$k] = [];
            foreach ($linked[$k] as $slug => $name) {
                
                $show[$k][] = ['slug' => (string) $slug, 'name' => $name];
            }
        }
        $show['links'] = array_values(array_unique($links));

        // angka award terstruktur (null = tidak ada angka, mis. teks "See the Top 250...")
        $show['winCount']        = isset($show['winCount']) ? (int) $show['winCount'] : null;
        $show['nominationCount'] = isset($show['nominationCount']) ? (int) $show['nominationCount'] : null;
        $show['awardResults']    = array_map(fn ($r) => [
            'name'      => $r['label'],
            'won'       => (int) ($r['won'] ?? 0),
            'nominated' => (int) ($r['nom'] ?? 0),
        ], $awardRows);

        $rt = $ratingRows[0] ?? [];
        $show['aggregateRating']  = isset($rt['rating']) ? (float) $rt['rating'] : 0;
        $show['interactionCount'] = isset($rt['votes']) ? (int) $rt['votes'] : 0;
        $show['bestRating']       = isset($rt['best']) ? (float) $rt['best'] : 10;
        $show['worstRating']      = isset($rt['worst']) ? (float) $rt['worst'] : 1;

        
        $show['rank'] = $this->ranks()[$id] ?? 0;

        // Similar recommendation (hanya memunculkan 12 series)
        $similar = $this->similarTo($id);

        // 'shows' -> untuk carousel similar
        return view('detail', ['show' => $show, 'shows' => $similar]);
    }
}