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

    // Field yang hanya dikirim ke browser kalau diminta lewat allShows([...])
    // _g / _a = daftar genre & aktor dalam bentuk array (khusus perhitungan similar & halaman person, tidak pernah dikirim)
    private const OPTIONAL = ['description', 'keywords', 'platform', 'author','productionCompany', 'numberOfEpisodes', 'contentRating', 'status', 'duration', '_g', '_a'];

    // ===== BARU: dipakai search & keyword (SPARQL) =====
    private const SORTS = [
        'rating' => 'DESC(xsd:decimal(?ratingValue)) DESC(xsd:integer(?votesValue))',
        'votes'  => 'DESC(xsd:integer(?votesValue))',
        'year'   => 'DESC(?startDateValue)',
        'name'   => 'ASC(LCASE(STR(?nameValue)))',
    ];

    private const PREFIXES = '
        PREFIX schema: <https://schema.org/>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
        PREFIX ex: <http://example.org/tvseries/vocab#>
    ';

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
        return Cache::remember('all_shows_v8', 3600, function () {
            $rows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
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
                    OPTIONAL { ?s schema:status ?status . }
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

            // keywords: satu baris per series
            $kw = [];
            $kwRows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                SELECT ?s (GROUP_CONCAT(DISTINCT ?k; separator="|") AS ?kw)
                WHERE { ?s a schema:TVSeries ; schema:keywords ?k . }
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
            // CATATAN: kalau halaman company kosong, coba ganti schema:productionCompany
            // dengan ex:productionCompany (+ PREFIX ex: <http://example.org/tvseries/vocab#>).
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
     */
    private function similarTo(string $id, array $cur, array $all, int $limit = 12): array
    {
        $G = array_flip($cur['_g']);
        $A = array_flip($cur['_a']);
        $K = array_flip($cur['keywords']);
        $nG = count($G);
        $nK = count($K);

        $scored = [];
        foreach ($all as $i => $s) {
            if ($s['id'] === $id) continue;

            $sg = array_flip($s['_g']);
            $g  = count(array_intersect_key($G, $sg));
            $a  = count(array_intersect_key($A, array_flip($s['_a'])));
            $k  = $nK ? count(array_intersect_key($K, array_flip($s['keywords']))) : 0;
            if (!$g && !$a && !$k) continue;

            $union    = $nG + count($sg) - $g;
            $genreSim = $union ? $g / $union : 0;
            $kwDen    = sqrt($nK * max(1, count($s['keywords'])));
            $kwSim    = ($k && $kwDen) ? $k / $kwDen : 0;
            $castSim  = min($a, 3) / 3;

            // + rating kecil sebagai tie-breaker
            $scored[] = [2 * $genreSim + 3 * $kwSim + 2 * $castSim + $s['aggregateRating'] / 100, $i];
        }

        usort($scored, fn ($x, $y) => $y[0] <=> $x[0]);

        $drop = array_flip(self::OPTIONAL);
        return array_map(
            fn ($row) => array_diff_key($all[$row[1]], $drop),
            array_slice($scored, 0, $limit)
        );
    }

    // =====================================================================
    // BARU: helper SPARQL untuk search & keyword
    // =====================================================================

    /** String literal SPARQL yang aman dari input pengguna. */
    private static function lit(string $v): string
    {
        $v = preg_replace('/[\r\n\t]+/', ' ', $v) ?? $v;
        return '"' . addcslashes($v, "\\\"") . '"';
    }

    /** FILTER: series punya objek (via $pred) yang label/nama-nya sama persis (case-insensitive). */
    private static function fLabel(string $pred, string $value): string
    {
        $v = self::lit(mb_strtolower(trim($value)));
        return "FILTER EXISTS {
            ?s $pred ?fx .
            OPTIONAL { ?fx rdfs:label ?fxl }
            FILTER(LCASE(STR(COALESCE(?fxl, REPLACE(REPLACE(STR(?fx), \"^.*[/#]\", \"\"), \"_\", \" \")))) = $v)
        }";
    }

    private static function fKeyword(string $k): string
    {
        $v = self::lit(mb_strtolower(trim($k)));
        return "FILTER EXISTS { ?s schema:keywords ?fk . FILTER(LCASE(STR(?fk)) = $v) }";
    }

    /** Bangun semua FILTER dari parameter search. Semua nilai di-escape / di-cast angka. */
    private function searchFilters(array $in): string
    {
        $f = [];

        if (($q = mb_strtolower(trim((string) ($in['q'] ?? '')))) !== '') {
            $v = self::lit($q);
            $f[] = "FILTER(
                CONTAINS(LCASE(STR(?name)), $v)
                || EXISTS { ?s schema:keywords ?qk . FILTER(CONTAINS(LCASE(STR(?qk)), $v)) }
                || EXISTS { ?s schema:actor ?qa . ?qa rdfs:label ?qal . FILTER(CONTAINS(LCASE(STR(?qal)), $v)) }
                || EXISTS { ?s schema:genre ?qg . ?qg rdfs:label ?qgl . FILTER(CONTAINS(LCASE(STR(?qgl)), $v)) }
            )";
        }

        // genre & platform: cocokkan lewat IRI entitas (slug atau nama dari tile)
        $linked = [
            'genre'    => ['schema:genre', self::GENRE_NS,    fn () => $this->genreTiles()],
            'platform' => ['ex:platform',  self::PLATFORM_NS, fn () => $this->platformTiles()],
        ];
        foreach ($linked as $key => [$pred, $ns, $tiles]) {
            $val = mb_strtolower(trim((string) ($in[$key] ?? '')));
            if ($val === '') continue;

            $hit = null;
            foreach ($tiles() as $t) {
                if ($t['slug'] === $val || mb_strtolower($t['name']) === $val) { $hit = $t['slug']; break; }
            }
            // slug berasal dari IRI di graf (bukan input mentah), aman dipakai di <...>
            $f[] = $hit ? "?s $pred <{$ns}{$hit}> ." : 'FILTER(false)';
        }

        // actor & country belum punya tile -> masih lewat label
        foreach (['actor' => 'schema:actor', 'country' => 'schema:countryOfOrigin'] as $key => $pred) {
            if (($val = trim((string) ($in[$key] ?? ''))) !== '') {
                $f[] = self::fLabel($pred, $val);
            }
        }

        if (($st = trim((string) ($in['status'] ?? ''))) !== '') {
            $f[] = 'FILTER EXISTS { ?s schema:status ?fs . FILTER(LCASE(STR(?fs)) = ' . self::lit(mb_strtolower($st)) . ') }';
        }

        if (isset($in['min_rating']) && is_numeric($in['min_rating'])) {
            $min = (float) $in['min_rating'];
            $f[] = "FILTER EXISTS { ?s schema:aggregateRating ?fr . ?fr schema:ratingValue ?frv .
                     FILTER(xsd:decimal(?frv) >= $min) }";
        }

        $yf = isset($in['year_from']) && is_numeric($in['year_from']) ? (int) $in['year_from'] : null;
        $yt = isset($in['year_to'])   && is_numeric($in['year_to'])   ? (int) $in['year_to']   : null;
        if ($yf !== null || $yt !== null) {
            $c = [];
            if ($yf !== null) $c[] = "xsd:integer(SUBSTR(STR(?fd), 1, 4)) >= $yf";
            if ($yt !== null) $c[] = "xsd:integer(SUBSTR(STR(?fd), 1, 4)) <= $yt";
            $f[] = 'FILTER EXISTS { ?s schema:startDate ?fd . FILTER(' . implode(' && ', $c) . ') }';
        }

        return implode("\n", $f);
    }

    /** Satu series dari baris hasil queryShows() -> bentuk yang sama dengan allShows(). */
    private static function cardRow(array $r): array
    {
        return [
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
            'platform'         => self::labels($r['platforms'] ?? null),
        ];
    }

    /**
     * Dua langkah supaya query berat hanya jalan untuk satu halaman:
     *  1) ambil IRI yang lolos filter, sudah diurutkan & dipotong (LIMIT/OFFSET)
     *  2) ambil field kartu hanya untuk IRI itu (VALUES), lalu urutkan ulang di PHP sesuai langkah 1
     */
    private function queryShows(string $filters, string $sort = 'rating', int $limit = 0, int $offset = 0): array
    {
        $order = self::SORTS[$sort] ?? self::SORTS['rating'];
        $page  = ($limit > 0 ? "LIMIT $limit" : '') . ($offset > 0 ? " OFFSET $offset" : '');

        // MIN/MAX (bukan SAMPLE) supaya urutan deterministik antar halaman
        $idRows = $this->sparql->query(self::PREFIXES . "
            SELECT ?s
                (MIN(?name) AS ?nameValue)
                (MIN(?startDate) AS ?startDateValue)
                (MAX(?rating) AS ?ratingValue)
                (MAX(?votes) AS ?votesValue)
            WHERE {
                ?s a schema:TVSeries ; schema:name ?name .
                $filters
                OPTIONAL { ?s schema:startDate ?startDate . }
                OPTIONAL {
                    ?s schema:aggregateRating ?r .
                    ?r schema:ratingValue ?rating .
                    OPTIONAL { ?r schema:ratingCount ?votes . }
                }
            }
            GROUP BY ?s
            ORDER BY $order ASC(LCASE(STR(?nameValue))) ASC(STR(?s))
            $page
        ");
        if (!$idRows) return [];

        $ids    = array_column($idRows, 's');
        $values = implode(' ', array_map(fn ($u) => '<' . $u . '>', $ids));

        $rows = $this->sparql->query(self::PREFIXES . "
            SELECT ?s (SAMPLE(DISTINCT ?name) AS ?nameValue)
                (SAMPLE(DISTINCT ?startDate) AS ?startDateValue)
                (SAMPLE(DISTINCT ?endDate) AS ?endDateValue)
                (SAMPLE(DISTINCT ?numberOfSeasons) AS ?numberOfSeasonsValue)
                (GROUP_CONCAT(DISTINCT STR(?award); separator='|') AS ?awards)
                (SAMPLE(DISTINCT ?image) AS ?imageValue)
                (SAMPLE(DISTINCT ?rating) AS ?ratingValue)
                (SAMPLE(DISTINCT ?votes) AS ?votesValue)
                (GROUP_CONCAT(DISTINCT COALESCE(?gl, STR(?g)); separator='|') AS ?genres)
                (GROUP_CONCAT(DISTINCT COALESCE(?al, STR(?a)); separator='|') AS ?actors)
                (GROUP_CONCAT(DISTINCT COALESCE(?cl, REPLACE(REPLACE(STR(?c), '^.*[/#]', ''), '_', ' ')); separator='|') AS ?countries)
                (GROUP_CONCAT(DISTINCT COALESCE(?pfl, STR(?pf)); separator='|') AS ?platforms)
            WHERE {
                VALUES ?s { $values }
                ?s schema:name ?name .
                OPTIONAL { ?s schema:startDate ?startDate . }
                OPTIONAL { ?s schema:endDate ?endDate . }
                OPTIONAL { ?s schema:numberOfSeasons ?numberOfSeasons . }
                OPTIONAL { ?s schema:award ?award . }
                OPTIONAL { ?s schema:image ?image . }
                OPTIONAL { ?s schema:genre ?g . OPTIONAL { ?g rdfs:label ?gl } }
                OPTIONAL { ?s schema:actor ?a . OPTIONAL { ?a rdfs:label ?al } }
                OPTIONAL { ?s schema:countryOfOrigin ?c . OPTIONAL { ?c rdfs:label ?cl } }
                OPTIONAL { ?s ex:platform ?pf . OPTIONAL { ?pf rdfs:label ?pfl } }
                OPTIONAL {
                    ?s schema:aggregateRating ?r .
                    ?r schema:ratingValue ?rating .
                    OPTIONAL { ?r schema:ratingCount ?votes . }
                }
            }
            GROUP BY ?s
        ");

        $byIri = [];
        foreach ($rows as $r) {
            $byIri[$r['s']] = self::cardRow($r);
        }

        $shows = [];
        foreach ($ids as $u) {          // pertahankan urutan dari langkah 1
            if (isset($byIri[$u])) $shows[] = $byIri[$u];
        }
        return $shows;
    }

    private function countShows(string $filters): int
    {
        $rows = $this->sparql->query(self::PREFIXES . "
            SELECT (COUNT(DISTINCT ?s) AS ?n)
            WHERE { ?s a schema:TVSeries ; schema:name ?name . $filters }
        ");
        return (int) ($rows[0]['n'] ?? 0);
    }

    /** Parameter search yang sudah dibersihkan (dipakai view & API). */
    private function searchParams(Request $request): array
    {
        $v = $request->validate([
            'q'          => ['nullable', 'string', 'max:100'],
            'genre'      => ['nullable', 'string', 'max:80'],
            'actor'      => ['nullable', 'string', 'max:80'],
            'country'    => ['nullable', 'string', 'max:80'],
            'platform'   => ['nullable', 'string', 'max:80'],
            'status'     => ['nullable', 'string', 'max:40'],
            'min_rating' => ['nullable', 'numeric', 'between:0,10'],
            'year_from'  => ['nullable', 'integer', 'between:1900,2100'],
            'year_to'    => ['nullable', 'integer', 'between:1900,2100'],
            'sort'       => ['nullable', 'in:' . implode(',', array_keys(self::SORTS))],
            'limit'      => ['nullable', 'integer', 'between:1,100'],
            'offset'     => ['nullable', 'integer', 'min:0'],
        ]);

        $v = array_map(fn ($x) => is_string($x) ? trim($x) : $x, $v);
        return array_filter($v, fn ($x) => $x !== null && $x !== '');
    }

    /** Hasil search (di-cache 10 menit per kombinasi parameter). */
    private function runSearch(array $p): array
    {
        $limit   = (int) ($p['limit'] ?? 48);
        $offset  = (int) ($p['offset'] ?? 0);
        $sort    = $p['sort'] ?? 'rating';

        // normalisasi untuk cache key ("Drama" == "drama ")
        $norm = array_map(fn ($x) => is_string($x) ? mb_strtolower($x) : $x, $p);
        ksort($norm);

        return Cache::remember('search_v2_' . md5(json_encode([$norm, $limit, $offset, $sort])), 600, function () use ($p, $sort, $limit, $offset) {
            $filters = $this->searchFilters($p);
            return [
                'shows'  => $this->queryShows($filters, $sort, $limit, $offset),
                'total'  => $this->countShows($filters),
                'limit'  => $limit,
                'offset' => $offset,
            ];
        });
    }

    // =====================================================================
    // Halaman & API
    // =====================================================================

    public function home()
    {
        return view('home', ['shows' => $this->allShows()]);
    }

    // BARU (SPARQL): /search?q=...&genre=... -> hasil awal dimuat dari Fuseki
    public function search(Request $request)
    {
        $params = $this->searchParams($request);

        return view('search', [
            'initial'   => $this->runSearch($params),   // {shows,total,limit,offset}
            'params'    => $params,
            'genres'    => array_column($this->genreTiles(), 'name'),
            'platforms' => array_column($this->platformTiles(), 'name'),
        ]);
    }

    // BARU (SPARQL): /api/search?... -> JSON untuk filter/pagination di browser
    public function searchApi(Request $request)
    {
        $request->headers->set('Accept', 'application/json'); // error validasi -> 422 JSON, bukan redirect
        return response()->json($this->runSearch($this->searchParams($request)));
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
    // /genre?genre=Nama -> link lama: dialihkan ke /genre/{slug}
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
    // /platform?platform=Nama -> link lama: dialihkan ke /platform/{slug}
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
        return view('category', ['shows' => $this->allShows(), 'slug' => $slug]);
    }

    // BARU (SPARQL): /keyword?k=new+mexico -> difilter langsung di Fuseki
    public function keyword(Request $request)
    {
        $raw = $request->query('k');                       // bisa array kalau ?k[]=x
        $k   = is_string($raw) ? mb_substr(mb_strtolower(trim($raw)), 0, 100) : '';

        $shows = $k === '' ? [] : Cache::remember(
            'keyword_shows_v2_' . md5($k),
            3600,
            fn () => $this->queryShows(self::fKeyword($k), 'rating')
        );

        return view('keyword', ['shows' => $shows, 'keyword' => $k]);
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
    // Dicari lewat IRI organization:{slug} di Fuseki, bukan lewat pencocokan nama.
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

    // /person/bryan-cranston -> series yang dibintangi ATAU dibuat orang itu.
    // Dicari lewat IRI person:{slug} di Fuseki, bukan lewat pencocokan nama.
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

        // dikirim sebagai string "A, B, C" (dipecah di detail.js)
        $multiString = ['countryOfOrigin', 'inLanguage'];
        // dikirim sebagai array (nama bisa mengandung koma)
        // actor, author, productionCompany, genre, platform TIDAK di sini: dikirim terpisah sebagai [{slug, name}]
        // untuk link ke /person/{slug}, /company/{slug}, /genre/{slug}, dan /platform/{slug}
        $multiArray  = ['spokenLanguage'];
        $linkedKeys  = ['actor', 'author', 'productionCompany', 'genre', 'platform'];
        // 'rank' sengaja TIDAK ada di sini: rank dihitung sendiri lewat ranks()
        $single = [
            'name', 'description', 'image', 'award', 'contentRating', 'slogan',
            'startDate', 'endDate', 'numberOfSeasons', 'numberOfEpisodes',
            'status', 'tmdbId', 'tvdbId', 'wikidataId', 'imdbId',
        ];

        $show  = ['id' => $id, 'uri' => $imdb];
        $lists = [];
        $linked = array_fill_keys($linkedKeys, []); // slug => nama (kunci slug mencegah duplikat)
        $keywords = [];
        $links = [];

        foreach ($rows as $r) {
            $key = preg_replace('/^.*[\/#]/', '', $r['p']);
            $val = $r['o'];
            $lbl = !empty($r['ol']) ? $r['ol'] : self::label($val);

            if (in_array($key, $linkedKeys, true)) {
                $linked[$key][basename(rtrim($val, '/'))] = $lbl;
            } elseif (in_array($key, $multiString, true) || in_array($key, $multiArray, true)) {
                $lists[$key][] = $lbl;
            } elseif ($key === 'keywords') {
                $keywords[] = $val;
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
                // (string): slug berupa angka murni otomatis jadi int sebagai kunci array PHP
                $show[$k][] = ['slug' => (string) $slug, 'name' => $name];
            }
        }
        $show['keywords'] = array_values(array_unique($keywords));
        $show['links']    = array_values(array_unique($links));

        $rt = $ratingRows[0] ?? [];
        $show['aggregateRating']  = isset($rt['rating']) ? (float) $rt['rating'] : 0;
        $show['interactionCount'] = isset($rt['votes']) ? (int) $rt['votes'] : 0;
        $show['bestRating']       = isset($rt['best']) ? (float) $rt['best'] : 10;
        $show['worstRating']      = isset($rt['worst']) ? (float) $rt['worst'] : 1;

        // Rank hasil hitung sendiri (sama dengan halaman Top Rated); 0 = tidak ada rank
        $show['rank'] = $this->ranks()[$id] ?? 0;

        // Similar recommendation (hanya memunculkan 12 series)
        $all = $this->loadShows();
        $cur = null;
        foreach ($all as $s) {
            if ($s['id'] === $id) { $cur = $s; break; }
        }
        $cur ??= [
            '_g'       => array_column($show['genre'], 'name'),
            '_a'       => array_column($show['actor'], 'name'),
            'keywords' => array_map('mb_strtolower', $show['keywords']),
        ];
        $similar = $this->similarTo($id, $cur, $all);

        // 'shows' -> untuk carousel similar
        return view('detail', ['show' => $show, 'shows' => $similar]);
    }
}