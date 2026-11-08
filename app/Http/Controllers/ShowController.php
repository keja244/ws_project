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

    // Prefix SPARQL yang dipakai semua query (prefix yang tidak terpakai tidak mengganggu)
    private const PREFIXES = '
        PREFIX schema: <https://schema.org/>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
        PREFIX ex: <http://example.org/tvseries/vocab#>
        PREFIX afn: <http://jena.apache.org/ARQ/function#>
    ';

    // Slug/id dihitung di graf: ".../series/tt0043208" -> "tt0043208"
    private const ID = '(REPLACE(STR(?s), "^.*/", "") AS ?id)';

    // Urutan standar
    private const BY_RATING = 'DESC(COALESCE(?rt, 0)) DESC(COALESCE(?votes, 0))';
    private const TIE       = ' ASC(STR(?s))';

    // Urutan "Top Rated" = ex:rank di graf (dipakai dengan CORE). Halaman Top Rated dan kategori top-picks.
    private const BY_RANK = 'ASC(COALESCE(?rank, 1000000)) ASC(STR(?s))';

    // Jumlah kartu per halaman di Top Rated (satu sumber untuk server dan JS)
    private const TOP_PER = 12;

    // Urutan untuk cardsWhere() (memakai alias hasil agregasi)
    private const CARD_ORDER = 'DESC(xsd:decimal(?ratingValue)) DESC(xsd:integer(?votesValue)) ASC(STR(?s))';

    // Negara Asia = entitas country:{slug} yang ADA di graf (bukan pencocokan teks nama)
    private const ASIA_SLUGS = ['japan', 'south-korea', 'china', 'india', 'hong-kong', 'singapore'];

    // Beranda: baris [slug, judul, fixed (tanpa "See more")], isinya dihitung di SPARQL
    private const HOME_LIMIT = 20;
    private const HOME_ROWS = [
        ['recommended',  'Recommended by Us',   true],
        ['top-picks',    'Top Picks',           true],
        ['popular',      'Most Popular',        false],
        ['long-running', 'Long-Running Series', false],
        ['awards',       'Award Winning',       false],
        ['asian',        'Top Asian',           false],
    ];

    // "Recommended by Us": pilihan manual (maks. 10). Kalau dikosongkan, dipilih acak harian
    // dari series ber-rating >= 8 (acak dihitung di SPARQL lewat SHA1, stabil sepanjang hari).
    private const RECOMMENDED_IDS = [
        'tt0903747', 'tt0944947', 'tt4574334', 'tt7366338', 'tt2861424',
        'tt1475582', 'tt0108778', 'tt0106179', 'tt0111161',
    ];

    // Inti per series. ?rank = ex:rank (rank Bayesian yang sudah tersimpan di graf)
    private const CORE = '
        { SELECT ?s (SAMPLE(?n) AS ?name) (MIN(?y0) AS ?yr) (MAX(?ns0) AS ?ns) (SAMPLE(?r0) AS ?rt) (SAMPLE(?v0) AS ?votes) (SAMPLE(?rk0) AS ?rank)
          WHERE {
            ?s a schema:TVSeries ; schema:name ?n .
            OPTIONAL { ?s schema:startDate ?sd . BIND(xsd:integer(SUBSTR(STR(?sd), 1, 4)) AS ?y0) }
            OPTIONAL { ?s schema:numberOfSeasons ?nsr . BIND(xsd:integer(?nsr) AS ?ns0) }
            OPTIONAL { ?s ex:rank ?rkr . BIND(xsd:integer(?rkr) AS ?rk0) }
            OPTIONAL {
                ?s schema:aggregateRating ?ar . ?ar schema:ratingValue ?rr .
                BIND(xsd:decimal(?rr) AS ?r0)
                OPTIONAL { ?ar schema:ratingCount ?vc . BIND(xsd:integer(?vc) AS ?v0) }
            }
          } GROUP BY ?s }
    ';

    // Hanya untuk mengurutkan kategori "awards" (jumlah menang)
    private const FEAT_WINS = '
        OPTIONAL { ?s ex:winCount ?wins }
    ';

    // Properti series yang dipakai halaman detail (nama lokal predikat)
    private const LINKED_KEYS = ['actor', 'author', 'productionCompany', 'genre', 'platform', 'keywords', 'countryOfOrigin', 'spokenLanguage'];
    private const DETAIL_KEYS = [
        'name', 'description', 'image', 'award', 'contentRating', 'slogan',
        'startDate', 'endDate', 'numberOfSeasons', 'numberOfEpisodes',
        'status', 'tmdbId', 'tvdbId', 'wikidataId', 'imdbId',
        'trailer', 'thumbnailUrl', 'alternateName',
        'winCount', 'nominationCount',
        'rank',                 // ex:rank (rank Bayesian yang tersimpan di graf)
        'duration', 'sameAs', 'inLanguage',
    ];

    public function __construct(private SparqlService $sparql) {}

    // ------------------------------------------------------------------
    // Helper kecil
    // ------------------------------------------------------------------

    /** Jalankan query SPARQL dengan prefix standar. */
    private function select(string $body): array
    {
        return $this->sparql->query(self::PREFIXES . $body);
    }

    // "Comedy|Drama" -> ['Comedy', 'Drama']  (hanya untuk bentuk array di JSON / chatbot)
    private static function labels(?string $concat): array
    {
        if (!$concat) return [];
        $parts = array_filter(array_map('trim', explode('|', $concat)), 'strlen');
        return array_values(array_unique($parts));
    }

    // Slug harus aman sebelum dipakai membentuk IRI
    private static function slugOr404(string $slug): string
    {
        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), 404);
        return $slug;
    }

    // Escape string dari input pengguna sebelum masuk ke query SPARQL
    private static function sparqlStr(string $v): string
    {
        return '"' . strtr($v, [
            '\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t',
        ]) . '"';
    }

    /**
     * Ekspresi SPARQL: durasi (ISO 8601 "PT1H30M" / "PT30M" atau teks "49 min") -> menit (integer).
     * $v = nama variabel SPARQL yang berisi durasi.
     */
    private static function minutesExpr(string $v): string
    {
        $e = 'IF(REGEX({S}, "^PT[0-9]+[HM]"), '
            . '(IF(REGEX({S}, "^PT[0-9]+H"), xsd:integer(REPLACE({S}, "^PT([0-9]+)H.*$", "$1")), 0) * 60 '
            . '+ IF(REGEX({S}, "^PT([0-9]+H)?[0-9]+M"), xsd:integer(REPLACE({S}, "^PT(?:[0-9]+H)?([0-9]+)M.*$", "$1")), 0)), '
            . 'xsd:integer(REPLACE({S}, "^[^0-9]*([0-9]+).*$", "$1")))';
        return str_replace('{S}', "STR($v)", $e);
    }

    /**
     * Subselect agregasi satu kolom multi-nilai per series (menggantikan join array di PHP
     * dan mencegah cartesian product genre x aktor x negara x ...).
     *   $alias   : nama variabel hasil
     *   $pred    : predikat (mis. 'schema:genre')
     *   $pattern : graph pattern pembatas (VALUES / relasi), harus dari kode
     *   $sep     : pemisah GROUP_CONCAT
     *   $raw     : true = nilai literal apa adanya (mis. award), false = rdfs:label entitas
     *   $lower   : huruf kecil (keywords)
     */
    private static function agg(string $alias, string $pred, string $pattern = '', string $sep = '|', bool $raw = false, bool $lower = false): string
    {
        $v = $raw
            ? 'STR(?o)'
            : 'COALESCE(STR(?l), REPLACE(REPLACE(STR(?o), "^.*[/#]", ""), "[_-]", " "))';
        if ($lower) $v = "LCASE($v)";
        $label = $raw ? '' : 'OPTIONAL { ?o rdfs:label ?l }';

        return "OPTIONAL { SELECT ?s (GROUP_CONCAT(DISTINCT $v; separator=\"$sep\") AS ?$alias) "
            . "WHERE { ?s a schema:TVSeries . $pattern ?s $pred ?o . $label } GROUP BY ?s }\n";
    }

    /** rdfs:label dari satu IRI entitas (null kalau tidak ada di graf / bukan kelas $class). */
    private function labelOf(string $iri, ?string $class = null): ?string
    {
        $type = $class ? "<$iri> a $class ." : '';
        $rows = $this->select("
            SELECT ?label WHERE { $type <$iri> rdfs:label ?label . } LIMIT 1
        ");
        return $rows[0]['label'] ?? null;
    }

    /** Slug entitas ber-kelas $class yang label-nya sama (tanpa peduli huruf besar/kecil). */
    private function slugByLabel(string $class, string $label): ?string
    {
        $rows = $this->select('
            SELECT (REPLACE(STR(?e), "^.*/", "") AS ?slug)
            WHERE {
                ?e a ' . $class . ' ; rdfs:label ?l .
                FILTER(LCASE(STR(?l)) = ' . self::sparqlStr(mb_strtolower($label)) . ')
            } LIMIT 1
        ');
        return $rows[0]['slug'] ?? null;
    }

    // ------------------------------------------------------------------
    // Dataset penuh untuk chatbot (satu query, tanpa join di PHP)
    // ------------------------------------------------------------------

    /**
     * Semua series dalam bentuk lengkap. Hanya dipakai ChatbotService.
     * Kolom multi-nilai diagregasi lewat subselect, bukan query terpisah yang digabung di PHP.
     */
    private function loadShows(): array
    {
        return Cache::remember('all_shows_v11', 3600, function () {
            $aggs = self::agg('genres', 'schema:genre')
                . self::agg('actors', 'schema:actor')
                . self::agg('countries', 'schema:countryOfOrigin')
                . self::agg('awards', 'schema:award', '', '|', true)
                . self::agg('keywords', 'schema:keywords', '', '|', false, true)
                . self::agg('platforms', 'ex:platform')
                . self::agg('authors', 'schema:author')
                . self::agg('companies', 'schema:productionCompany');
            $dur = self::minutesExpr('?duration');
            $id  = self::ID;

            $rows = $this->select("
                SELECT ?s $id ?genres ?actors ?countries ?awards ?keywords ?platforms ?authors ?companies
                    (SAMPLE(?name) AS ?nameValue)
                    (SAMPLE(?startDate) AS ?startDateValue)
                    (SAMPLE(?endDate) AS ?endDateValue)
                    (SAMPLE(?numberOfSeasons) AS ?numberOfSeasonsValue)
                    (SAMPLE(?numberOfEpisodes) AS ?numberOfEpisodesValue)
                    (SAMPLE(?contentRating) AS ?contentRatingValue)
                    (SAMPLE(?status) AS ?statusValue)
                    (SAMPLE(?dmin) AS ?durationValue)
                    (SAMPLE(?image) AS ?imageValue)
                    (SAMPLE(?description) AS ?descriptionValue)
                    (SAMPLE(?rating) AS ?ratingValue)
                    (SAMPLE(?votes) AS ?votesValue)
                WHERE {
                    ?s a schema:TVSeries ; schema:name ?name .
                    OPTIONAL { ?s schema:startDate ?startDate . }
                    OPTIONAL { ?s schema:endDate ?endDate . }
                    OPTIONAL { ?s schema:numberOfSeasons ?numberOfSeasons . }
                    OPTIONAL { ?s schema:numberOfEpisodes ?numberOfEpisodes . }
                    OPTIONAL { ?s schema:contentRating ?contentRating . }
                    OPTIONAL { ?s ex:status ?status . }
                    OPTIONAL { ?s schema:duration ?duration . BIND($dur AS ?dmin) }
                    OPTIONAL { ?s schema:image ?image . }
                    OPTIONAL { ?s schema:description ?description . }
                    OPTIONAL {
                        ?s schema:aggregateRating ?r .
                        ?r schema:ratingValue ?rating .
                        OPTIONAL { ?r schema:ratingCount ?votes }
                    }
                    $aggs
                }
                GROUP BY ?s ?genres ?actors ?countries ?awards ?keywords ?platforms ?authors ?companies
                ORDER BY DESC(xsd:decimal(?ratingValue)) ASC(STR(?s))
            ");

            return array_map(function ($r) {
                $g = self::labels($r['genres'] ?? null);
                $a = self::labels($r['actors'] ?? null);
                return [
                    'id'                => $r['id'],
                    'name'              => $r['nameValue'],
                    'aggregateRating'   => isset($r['ratingValue']) ? (float) $r['ratingValue'] : 0,
                    'interactionCount'  => isset($r['votesValue']) ? (int) $r['votesValue'] : 0,
                    'startDate'         => $r['startDateValue'] ?? null,
                    'endDate'           => $r['endDateValue'] ?? null,
                    'numberOfSeasons'   => $r['numberOfSeasonsValue'] ?? null,
                    'numberOfEpisodes'  => $r['numberOfEpisodesValue'] ?? null,
                    'contentRating'     => $r['contentRatingValue'] ?? '',
                    'status'            => $r['statusValue'] ?? '',
                    'duration'          => (int) ($r['durationValue'] ?? 0),
                    'award'             => implode(', ', self::labels($r['awards'] ?? null)),
                    'countryOfOrigin'   => implode(', ', self::labels($r['countries'] ?? null)),
                    'image'             => $r['imageValue'] ?? '',
                    'description'       => $r['descriptionValue'] ?? '',
                    'genre'             => implode(', ', $g),
                    'actor'             => implode(', ', $a),
                    'platform'          => self::labels($r['platforms'] ?? null),
                    'keywords'          => self::labels($r['keywords'] ?? null),
                    'author'            => self::labels($r['authors'] ?? null),
                    'productionCompany' => self::labels($r['companies'] ?? null),
                    '_g'                => $g,
                    '_a'                => $a,
                ];
            }, $rows);
        });
    }

    // ------------------------------------------------------------------
    // Kartu series
    // ------------------------------------------------------------------

    /**
     * Kartu series, difilter oleh $pattern (graph pattern SPARQL yang memakai ?s).
     * $pattern HANYA boleh dari kode; nilai dari pengguna wajib lewat sparqlStr().
     * Kolom multi-nilai (genre, aktor, negara, award) diagregasi lewat subselect per kolom
     * sehingga tidak ada cartesian product. Setiap kartu membawa 'rank' = ex:rank (0 = tidak ada).
     * Kalau $pattern memuat VALUES (?s ?pos), urutan ditentukan ?pos (lihat cardsByIris()).
     */
    private function cardsWhere(string $pattern, string $order = self::CARD_ORDER, bool $withDescription = false): array
    {
        $aggs = self::agg('genres', 'schema:genre', $pattern, ', ')
            . self::agg('actors', 'schema:actor', $pattern, ', ')
            . self::agg('countries', 'schema:countryOfOrigin', $pattern, ', ')
            . self::agg('awards', 'schema:award', $pattern, ', ', true);
        $pos     = str_contains($pattern, '?pos') ? '(MIN(?pos) AS ?posValue)' : '';
        $descSel = $withDescription ? '(SAMPLE(?description) AS ?descriptionValue)' : '';
        $descOpt = $withDescription ? 'OPTIONAL { ?s schema:description ?description . }' : '';
        $id      = self::ID;

        $rows = $this->select("
            SELECT ?s $id ?genres ?actors ?countries ?awards
                (SAMPLE(?name) AS ?nameValue)
                (SAMPLE(?startDate) AS ?startDateValue)
                (SAMPLE(?endDate) AS ?endDateValue)
                (SAMPLE(?numberOfSeasons) AS ?numberOfSeasonsValue)
                (SAMPLE(?image) AS ?imageValue)
                (SAMPLE(?rating) AS ?ratingValue)
                (SAMPLE(?votes) AS ?votesValue)
                (SAMPLE(?rank) AS ?rankValue)
                $descSel
                $pos
            WHERE {
                ?s a schema:TVSeries ; schema:name ?name .
                $pattern
                OPTIONAL { ?s schema:startDate ?startDate . }
                OPTIONAL { ?s schema:endDate ?endDate . }
                OPTIONAL { ?s schema:numberOfSeasons ?numberOfSeasons . }
                OPTIONAL { ?s schema:image ?image . }
                OPTIONAL { ?s ex:rank ?rank . }
                $descOpt
                OPTIONAL {
                    ?s schema:aggregateRating ?r .
                    ?r schema:ratingValue ?rating .
                    OPTIONAL { ?r schema:ratingCount ?votes }
                }
                $aggs
            }
            GROUP BY ?s ?genres ?actors ?countries ?awards
            ORDER BY $order
        ");

        return array_map(fn ($r) => [
            'id'               => $r['id'],
            'name'             => $r['nameValue'],
            'rank'             => isset($r['rankValue']) ? (int) $r['rankValue'] : 0,
            'aggregateRating'  => isset($r['ratingValue']) ? (float) $r['ratingValue'] : 0,
            'interactionCount' => isset($r['votesValue']) ? (int) $r['votesValue'] : 0,
            'startDate'        => $r['startDateValue'] ?? null,
            'endDate'          => $r['endDateValue'] ?? null,
            'numberOfSeasons'  => $r['numberOfSeasonsValue'] ?? null,
            'award'            => $r['awards'] ?? '',
            'countryOfOrigin'  => $r['countries'] ?? '',
            'image'            => $r['imageValue'] ?? '',
            'genre'            => $r['genres'] ?? '',
            'actor'            => $r['actors'] ?? '',
        ] + ($withDescription ? ['description' => $r['descriptionValue'] ?? ''] : []), $rows);
    }

    /**
     * Series (field kartu saja) yang terhubung ke satu entitas lewat satu predikat:
     *   ?s {$predicate} <$iri>
     * Dipakai halaman company, country, language.
     * $predicate HANYA boleh dari kode (konstanta), bukan dari input pengguna.
     */
    private function cardsLinkedTo(string $predicate, string $iri): array
    {
        $id = self::ID;
        $rows = $this->select("
            SELECT ?s $id
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
            ORDER BY DESC(xsd:decimal(?ratingValue)) DESC(xsd:integer(?votesValue)) ASC(STR(?s))
        ");

        return array_map(fn ($r) => [
            'id'               => $r['id'],
            'name'             => $r['nameValue'],
            'aggregateRating'  => isset($r['ratingValue']) ? (float) $r['ratingValue'] : 0,
            'interactionCount' => isset($r['votesValue']) ? (int) $r['votesValue'] : 0,
            'startDate'        => $r['startDateValue'] ?? null,
            'endDate'          => $r['endDateValue'] ?? null,
            'image'            => $r['imageValue'] ?? '',
            'numberOfSeasons'  => $r['seasonsValue'] ?? null,
        ], $rows);
    }

    /** Kartu untuk daftar IRI; urutan ditentukan di SPARQL lewat VALUES (?s ?pos). */
    private function cardsByIris(array $iris, bool $withDescription = false): array
    {
        if (!$iris) return [];
        $values = implode(' ', array_map(
            fn ($iri, $n) => '(<' . $iri . '> ' . ($n + 1) . ')',
            $iris,
            array_keys($iris)
        ));
        return $this->cardsWhere("VALUES (?s ?pos) { $values }", 'ASC(?posValue)', $withDescription);
    }

    // ------------------------------------------------------------------
    // SEARCH, CATEGORY, TOP RATED (SPARQL): filter, skor, urutan, rank, hitungan,
    // dan paginasi (LIMIT/OFFSET) semuanya dikerjakan di graf.
    // PHP hanya merakit teks query.
    // ------------------------------------------------------------------

    // Sama dengan norm() di search-core.js: buang aksen + huruf kecil (pada input pengguna)
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

    // Sama dengan yearHit() di search-core.js, tapi menghasilkan [bawah, atas, skor]
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
     * Teks label ternormalisasi (huruf kecil, tanpa tanda baca) per series, dikumpulkan SEKALI
     * lewat subselect (pemisah "|"), sehingga pencocokan token tidak perlu EXISTS per token.
     */
    private static function textOf(string $alias, string $pred): string
    {
        return "OPTIONAL { SELECT ?s (GROUP_CONCAT(DISTINCT REPLACE(LCASE(STR(?l)), \"[^a-z0-9+ ]+\", \" \"); separator=\"|\") AS ?{$alias}) "
            . "WHERE { ?s {$pred} ?e . ?e rdfs:label ?l } GROUP BY ?s }\n";
    }

    // Graf: ada relasi ?s {$pred} ?e dan label entitas ?e (huruf kecil) termasuk salah satu $values
    private static function hasLabel(string $pred, array $values): string
    {
        $in = implode(', ', array_map(fn ($v) => self::sparqlStr(mb_strtolower($v)), $values));
        return "EXISTS { ?s $pred ?e . ?e rdfs:label ?l . FILTER(LCASE(STR(?l)) IN ($in)) }";
    }

    /**
     * Susun query pencarian. Return [fitur (OPTIONAL + BIND skor), FILTER, ORDER BY].
     * Skor relevansi: judul 6/5/3, genre 4/2, cast 3/2, tahun 3/2, platform & negara 1.5 (scope "all"),
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

        $features = '';
        $conds    = [];
        $terms    = [];
        if ($toks) {
            // teks label per series, dikumpulkan sekali
            if ($useGenre) $features .= self::textOf('gtxt', 'schema:genre');
            if ($useCast)  $features .= self::textOf('ctxt', 'schema:actor');
            if ($useMeta) {
                $features .= self::textOf('ptxt', 'ex:platform');
                $features .= self::textOf('ktxt', 'schema:countryOfOrigin');
            }

            $features .= 'BIND(REPLACE(LCASE(STR(?name)), "[^a-z0-9+ ]+", " ") AS ?tl)' . "\n";
            if ($useGenre) $features .= 'BIND(COALESCE(?gtxt, "") AS ?gt)' . "\n";
            if ($useCast)  $features .= 'BIND(COALESCE(?ctxt, "") AS ?ct)' . "\n";
            if ($useMeta) {
                $features .= 'BIND(COALESCE(?ptxt, "") AS ?pt)' . "\n";
                $features .= 'BIND(COALESCE(?ktxt, "") AS ?kt)' . "\n";
            }

            foreach ($toks as $i => $tok) {
                $T = self::sparqlStr($tok);
                $c = [];
                if ($useTitle) {
                    $features .= "BIND(IF(STRSTARTS(?tl, $T), 6, IF(CONTAINS(CONCAT(' ', ?tl), CONCAT(' ', $T)), 5, IF(CONTAINS(?tl, $T), 3, 0))) AS ?ti{$i})\n";
                    $c[] = "?ti{$i}";
                }
                if ($useGenre) {
                    $features .= "BIND(IF(CONTAINS(CONCAT('|', ?gt), CONCAT('|', $T)), 4, IF(CONTAINS(?gt, $T), 2, 0)) AS ?tg{$i})\n";
                    $c[] = "?tg{$i}";
                }
                if ($useCast) {
                    $features .= "BIND(IF(CONTAINS(REPLACE(CONCAT('|', ?ct), '[|]', ' '), CONCAT(' ', $T)), 3, IF(CONTAINS(?ct, $T), 2, 0)) AS ?tc{$i})\n";
                    $c[] = "?tc{$i}";
                }
                if ($useYear && ($yr = self::yearRange($tok))) {
                    [$lo, $hi, $sc] = $yr;
                    $features .= "BIND(IF(BOUND(?yr) && ?yr >= $lo && ?yr <= $hi, $sc, 0) AS ?ty{$i})\n";
                    $c[] = "?ty{$i}";
                }
                if ($useMeta) {
                    $features .= "BIND(IF(CONTAINS(?pt, $T), 1.5, 0) AS ?tp{$i})\n";
                    $features .= "BIND(IF(CONTAINS(?kt, $T), 1.5, 0) AS ?tk{$i})\n";
                    $c[] = "?tp{$i}";
                    $c[] = "?tk{$i}";
                }
                if (!$c) $c[] = '0';

                // skor token = nilai terbesar di antara kolom yang cocok
                $cur = array_shift($c);
                foreach ($c as $j => $v) {
                    $features .= "BIND(IF($cur > $v, $cur, $v) AS ?m{$i}_{$j})\n";
                    $cur = "?m{$i}_{$j}";
                }
                $terms[] = $cur;
                $conds[] = "$cur > 0";
            }

            // bonus frasa
            if (count($toks) > 1) {
                $ph = self::sparqlStr(implode(' ', $toks));
                if ($useTitle) $terms[] = "IF(CONTAINS(?tl, $ph), 6, 0)";
                if ($useCast)  $terms[] = "IF(CONTAINS(?ct, $ph), 4, 0)";
            }
            $features .= 'BIND(' . implode(' + ', $terms) . " AS ?score)\n";
        }

        // filter terstruktur = pola relasi di graf
        $filters = [];
        if ($conds) $filters[] = '(' . implode(' && ', $conds) . ')';
        foreach ($p['genres'] as $g) {
            $filters[] = self::hasLabel('schema:genre', [$g]);
        }
        if ($p['platform']) $filters[] = self::hasLabel('ex:platform', $p['platform']);
        if ($p['country'])  $filters[] = self::hasLabel('schema:countryOfOrigin', $p['country']);
        if ($p['awards'])           $filters[] = 'EXISTS { ?s ex:winCount ?w . FILTER(?w > 0) }';
        if ($p['from'] !== null)    $filters[] = '(BOUND(?yr) && ?yr >= ' . (int) $p['from'] . ')';
        if ($p['to'] !== null)      $filters[] = '(BOUND(?yr) && ?yr <= ' . (int) $p['to'] . ')';
        if ($p['rating'] !== null)  $filters[] = '(COALESCE(?rt, 0) >= ' . sprintf('%F', $p['rating']) . ')';
        if ($p['seasons'] !== null) $filters[] = '(COALESCE(?ns, 0) >= ' . (int) $p['seasons'] . ')';
        if ($p['smax'] !== null)    $filters[] = '(COALESCE(?ns, 0) > 0 && ?ns <= ' . (int) $p['smax'] . ')';

        // urutan (ASC(STR(?s)) = tie-breaker supaya halaman tidak saling tumpang tindih)
        $sort  = $p['sort'] ?: ($toks ? 'relevance' : 'rating');
        $order = match ($sort) {
            'relevance' => $toks ? 'DESC(?score) DESC(COALESCE(?votes, 0))' : self::BY_RATING,
            'votes'     => 'DESC(COALESCE(?votes, 0)) DESC(COALESCE(?rt, 0))',
            'newest'    => 'DESC(COALESCE(?yr, 0)) ' . self::BY_RATING,
            'oldest'    => 'ASC(COALESCE(?yr, 9999)) ' . self::BY_RATING,
            'seasons'   => 'DESC(COALESCE(?ns, 0)) ' . self::BY_RATING,
            default     => self::BY_RATING,
        };

        return [$features, implode(' && ', $filters), $order . self::TIE];
    }

    // Subselect "IRI series yang lolos filter" (dipakai query halaman dan query hitung)
    private static function irisQuery(string $features, string $filters): string
    {
        return 'SELECT ?s WHERE { ' . self::CORE . " $features "
            . ($filters !== '' ? "FILTER($filters)" : '') . ' }';
    }

    /** Satu halaman IRI series yang lolos filter, terurut. LIMIT/OFFSET dikerjakan graf. */
    private function rankedIris(string $features, string $filters, string $order, int $limit, int $offset = 0): array
    {
        $rows = $this->select(
            self::irisQuery($features, $filters)
            . " ORDER BY $order LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
        );
        return array_column($rows, 's');
    }

    /** Jumlah series yang lolos filter (dibatasi $cap kalau ada, mis. top-picks = 10). */
    private function countIris(string $features, string $filters, string $order, ?int $cap): int
    {
        $inner = self::irisQuery($features, $filters)
            . ($cap ? " ORDER BY $order LIMIT " . (int) $cap : '');
        $rows = $this->select("SELECT (COUNT(*) AS ?n) WHERE { $inner }");
        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * Hasil berhalaman: jumlah (COUNT) dan halaman (LIMIT/OFFSET) diambil dari graf,
     * lalu kartu halaman itu diambil lewat cardsByIris(). ?page=N&per=M
     * $spec = [fitur, filter, order, cap|null]
     */
    private function paged(Request $request, string $cacheKey, array $spec, int $ttl, int $defaultPer = 21, bool $withDescription = false): array
    {
        [$features, $filters, $order, $cap] = $spec;

        $per   = min(max((int) $request->query('per', $defaultPer), 1), 50);
        $total = Cache::remember(
            $cacheKey . '_n',
            $ttl,
            fn () => $this->countIris($features, $filters, $order, $cap)
        );
        $pages = max(1, (int) ceil($total / $per));
        $page  = min(max((int) $request->query('page', 1), 1), $pages);
        $off   = ($page - 1) * $per;

        $iris = $total === 0 ? [] : Cache::remember(
            "{$cacheKey}_{$per}_{$page}",
            $ttl,
            fn () => $this->rankedIris($features, $filters, $order, min($per, $total - $off), $off)
        );

        return [
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'shows' => $this->cardsByIris($iris, $withDescription),
        ];
    }

    // Spesifikasi satu kategori: [fitur, filter, order, cap]
    private function categorySpec(string $slug): array
    {
        return match ($slug) {
            // 10 teratas menurut ex:rank
            'top-picks'    => ['', '', self::BY_RANK, 10],
            // votes terbanyak
            'popular'      => ['', '', 'DESC(COALESCE(?votes, 0)) DESC(COALESCE(?rt, 0))' . self::TIE, null],
            // minimal 2 season, season terbanyak dulu
            'long-running' => ['', 'COALESCE(?ns, 0) >= 2', 'DESC(COALESCE(?ns, 0)) ' . self::BY_RATING . self::TIE, null],
            // pernah menang (ex:winCount), jumlah menang terbanyak dulu
            'awards'       => [self::FEAT_WINS, 'COALESCE(?wins, 0) > 0', 'DESC(COALESCE(?wins, 0)) ' . self::BY_RATING . self::TIE, null],
            // asian: negara asalnya salah satu entitas country:{slug} Asia
            default        => ['', 'EXISTS { ?s schema:countryOfOrigin ?ac . FILTER(?ac IN ('
                . implode(', ', array_map(fn ($s) => '<' . self::COUNTRY_NS . $s . '>', self::ASIA_SLUGS))
                . ')) }', self::BY_RATING . self::TIE, null],
        };
    }

    /**
     * Facet panel filter (genre, platform, negara, rentang tahun). Kecil, di-cache.
     * Dikirim ke halaman search lewat window.ITV_FACETS.
     */
    private function searchFacets(): array
    {
        return Cache::remember('search_facets_v2', 3600, function () {
            $count = function (string $pattern, string $label): array {
                $rows = $this->select("
                    SELECT ?name (COUNT(DISTINCT ?s) AS ?n)
                    WHERE { ?s a schema:TVSeries . $pattern BIND($label AS ?name) }
                    GROUP BY ?name
                    ORDER BY DESC(?n) ASC(?name)
                ");
                return array_map(fn ($r) => ['name' => $r['name'], 'n' => (int) $r['n']], $rows);
            };

            $years = $this->select('
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
        $p    = $this->searchParams($request);
        $plan = $this->searchPlan($p);
        $key  = 'search_v3_' . md5(implode('|', $plan));

        return response()->json($this->paged($request, $key, [...$plan, null], 600));
    }

    // /api/category/popular?page=2 -> {total, page, pages, shows:[kartu]}
    public function categoryShows(Request $request, string $slug)
    {
        abort_unless(in_array($slug, ['top-picks', 'popular', 'long-running', 'awards', 'asian'], true), 404);

        return response()->json($this->paged($request, 'category_v3_' . $slug, $this->categorySpec($slug), 3600));
    }

    // /api/top-rated?page=2&per=12 -> {total, page, pages, shows:[kartu + 'rank' + 'description']}
    // Urutan = ex:rank di graf, sama dengan angka rank di halaman detail.
    public function topRatedShows(Request $request)
    {
        return response()->json(
            $this->paged(
                $request,
                'top_rated_v3',
                ['', 'BOUND(?rank)', self::BY_RANK, null],
                3600,
                self::TOP_PER,
                true
            )
        );
    }

    /**
     * Posisi (1-based) sebuah rank dalam urutan Top Rated, dihitung di graf:
     * jumlah series ber-rank lebih kecil + 1. Null kalau tidak ada series dengan rank itu.
     */
    private function rankPosition(int $rank): ?int
    {
        return Cache::remember("rank_pos_v1_$rank", 3600, function () use ($rank) {
            $rows = $this->select("
                SELECT (SUM(IF(?rk < $rank, 1, 0)) AS ?before)
                       (SUM(IF(?rk = $rank, 1, 0)) AS ?hit)
                WHERE {
                    ?s a schema:TVSeries ; ex:rank ?rkr .
                    BIND(xsd:integer(?rkr) AS ?rk)
                    FILTER(?rk <= $rank)
                }
            ");
            if ((int) ($rows[0]['hit'] ?? 0) === 0) return null;
            return (int) ($rows[0]['before'] ?? 0) + 1;
        });
    }

    /**
     * Rekomendasi mirip: skor gabungan genre (Jaccard), keywords (cosine), cast (maks 3 orang).
     * Series yang tidak berbagi apa pun dengan series ini tidak ikut.
     * Skor dihitung di SPARQL (butuh Jena/Fuseki: afn:sqrt), lalu kartu 12 teratas diambil lewat cardsByIris().
     */
    private function similarTo(string $id, int $limit = 12): array
    {
        $uri = self::SERIES_NS . $id;

        // + rating kecil sebagai tie-breaker (?rt / 100)
        $rows = $this->select("
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
            ORDER BY DESC(?score) ASC(STR(?s))
            LIMIT " . (int) $limit . "
        ");

        return $this->cardsByIris(array_column($rows, 's'));
    }

    // ------------------------------------------------------------------
    // Beranda: setiap baris dihitung di SPARQL dengan spesifikasi yang SAMA
    // dengan halaman "See more" (categorySpec), jadi isinya selalu konsisten.
    // ------------------------------------------------------------------

    /** Kartu satu kategori (maks. $limit), memakai categorySpec() yang sama dengan /api/category. */
    private function categoryCards(string $slug, int $limit): array
    {
        [$features, $filters, $order, $cap] = $this->categorySpec($slug);
        $limit = $cap ? min($cap, $limit) : $limit;

        return $this->cardsByIris($this->rankedIris($features, $filters, $order, $limit));
    }

    /** "Recommended by Us": ID manual kalau ada, kalau tidak acak harian (SHA1 + tanggal) di graf. */
    private function recommendedCards(): array
    {
        $ids = array_values(array_unique(array_filter(
            self::RECOMMENDED_IDS,
            fn ($i) => is_string($i) && preg_match('/^tt\d+$/', $i)
        )));

        if ($ids) {
            return $this->cardsByIris(array_map(fn ($i) => self::SERIES_NS . $i, array_slice($ids, 0, 10)));
        }

        $seed = date('Ymd');
        return $this->cardsByIris($this->rankedIris(
            '',
            'COALESCE(?rt, 0) >= 8',
            'ASC(SHA1(CONCAT(STR(?s), "' . $seed . '")))' . self::TIE,
            10
        ));
    }

    /** Semua baris beranda: [{slug, title, fixed, shows:[kartu]}] (baris kosong dibuang). */
    private function homeRows(): array
    {
        $out = [];
        foreach (self::HOME_ROWS as [$slug, $title, $fixed]) {
            $shows = $slug === 'recommended'
                ? $this->recommendedCards()
                : $this->categoryCards($slug, self::HOME_LIMIT);
            if ($shows) {
                $out[] = ['slug' => $slug, 'title' => $title, 'fixed' => $fixed, 'shows' => $shows];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Halaman
    // ------------------------------------------------------------------

    public function home()
    {
        // Cache per hari (kunci memuat tanggal) karena "recommended" bisa acak harian
        $rows = Cache::remember('home_rows_v1_' . date('Ymd'), 3600, fn () => $this->homeRows());

        // 'shows' dikosongkan: beranda tidak lagi mengirim seluruh series ke browser
        return view('home', ['shows' => [], 'rows' => $rows]);
    }

    public function search()
    {
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
     * Tile entitas (genre / platform): satu entitas $class per tile, jumlah series lewat $pred,
     * cover = series ber-rating tertinggi yang punya gambar (dipilih di graf lewat FILTER NOT EXISTS),
     * dan pengurutan ($order) juga di graf. Hasil kecil, di-cache.
     */
    private function entityTiles(string $cacheKey, string $class, string $pred, string $order): array
    {
        return Cache::remember($cacheKey, 3600, function () use ($class, $pred, $order) {
            $rows = $this->select("
                SELECT (REPLACE(STR(?e), \"^.*/\", \"\") AS ?slug)
                    (SAMPLE(?label) AS ?name)
                    (COUNT(DISTINCT ?s) AS ?n)
                    (SAMPLE(?img) AS ?cover)
                WHERE {
                    ?e a $class ; rdfs:label ?label .
                    ?s a schema:TVSeries ; $pred ?e .
                    OPTIONAL {
                        SELECT ?e (SAMPLE(?image) AS ?img) WHERE {
                            ?c1 $pred ?e ; schema:image ?image ; schema:aggregateRating/schema:ratingValue ?r1 .
                            FILTER NOT EXISTS {
                                ?c2 $pred ?e ; schema:image ?i2 ; schema:aggregateRating/schema:ratingValue ?r2 .
                                FILTER(xsd:decimal(?r2) > xsd:decimal(?r1)
                                    || (xsd:decimal(?r2) = xsd:decimal(?r1) && STR(?c2) < STR(?c1)))
                            }
                        } GROUP BY ?e
                    }
                }
                GROUP BY ?e
                ORDER BY $order
            ");

            return array_map(fn ($r) => [
                'slug'  => $r['slug'],
                'name'  => $r['name'],
                'count' => (int) $r['n'],
                'cover' => $r['cover'] ?? '',
            ], $rows);
        });
    }

    private function genreTiles(): array
    {
        return $this->entityTiles('genre_tiles_v2', 'ex:Genre', 'schema:genre', 'ASC(LCASE(?name))');
    }

    // Yang paling banyak series-nya di depan
    private function platformTiles(): array
    {
        return $this->entityTiles('platform_tiles_v2', 'ex:Platform', 'ex:platform', 'DESC(?n) ASC(LCASE(?name))');
    }

    /**
     * Series yang terhubung ke satu entitas ($pred -> <$iri>), lengkap dengan
     * pencarian judul (?q), urutan (?sort) dan paginasi (?page, ?per), semuanya di SPARQL.
     * Dipakai /api/genre/{slug} dan /api/platform/{slug}.
     * $pred HANYA boleh dari kode; $iri disusun dari slug yang sudah divalidasi.
     */
    private function entityShows(Request $request, string $cachePrefix, string $pred, string $iri): array
    {
        $q    = mb_strtolower(mb_substr(trim((string) $request->query('q', '')), 0, 100));
        $sort = (string) $request->query('sort', 'rating');
        $sort = in_array($sort, ['rating', 'votes', 'newest', 'seasons'], true) ? $sort : 'rating';

        $order = match ($sort) {
            'votes'   => 'DESC(COALESCE(?votes, 0)) DESC(COALESCE(?rt, 0))',
            'newest'  => 'DESC(COALESCE(?yr, 0)) ' . self::BY_RATING,
            'seasons' => 'DESC(COALESCE(?ns, 0)) ' . self::BY_RATING,
            default   => self::BY_RATING,
        } . self::TIE;

        $features = "?s $pred <$iri> .\n";
        $filters  = $q !== '' ? 'CONTAINS(LCASE(STR(?name)), ' . self::sparqlStr($q) . ')' : '';
        $key      = $cachePrefix . md5("$pred|$iri|$q|$sort");

        return $this->paged($request, $key, [$features, $filters, $order, null], 3600);
    }

    // /genre            -> daftar tile
    // /genre/{slug}     -> daftar tile + hasil genre itu (dimuat browser lewat /api/genre/{slug})
    public function genre(Request $request, ?string $slug = null)
    {
        if ($slug === null) {
            $legacy = trim((string) $request->query('genre', ''));
            if ($legacy !== '' && ($found = $this->slugByLabel('ex:Genre', $legacy)) !== null) {
                $rest = $request->except('genre');
                return redirect()->to(
                    url('/genre/' . $found) . ($rest ? '?' . http_build_query($rest) : ''),
                    301
                );
            }
        } else {
            self::slugOr404($slug);
            abort_if($this->labelOf(self::GENRE_NS . $slug, 'ex:Genre') === null, 404);
        }

        return view('genre', ['tiles' => $this->genreTiles(), 'initial' => $slug]);
    }

    // /api/genre/drama?q=&sort=&page=&per= -> {total, page, pages, shows:[kartu]}
    public function genreShows(Request $request, string $slug)
    {
        self::slugOr404($slug);
        $uri = self::GENRE_NS . $slug;
        abort_if($this->labelOf($uri, 'ex:Genre') === null, 404);

        return response()->json($this->entityShows($request, 'genre_shows_v2_', 'schema:genre', $uri));
    }

    // /platform            -> daftar tile
    // /platform/{slug}     -> daftar tile + hasil platform itu (dimuat browser lewat /api/platform/{slug})
    public function platform(Request $request, ?string $slug = null)
    {
        if ($slug === null) {
            $legacy = trim((string) $request->query('platform', ''));
            if ($legacy !== '' && ($found = $this->slugByLabel('ex:Platform', $legacy)) !== null) {
                $rest = $request->except('platform');
                return redirect()->to(
                    url('/platform/' . $found) . ($rest ? '?' . http_build_query($rest) : ''),
                    301
                );
            }
        } else {
            self::slugOr404($slug);
            abort_if($this->labelOf(self::PLATFORM_NS . $slug, 'ex:Platform') === null, 404);
        }

        return view('platform', ['tiles' => $this->platformTiles(), 'initial' => $slug]);
    }

    // /api/platform/netflix?q=&sort=&page=&per= -> {total, page, pages, shows:[kartu]}
    public function platformShows(Request $request, string $slug)
    {
        self::slugOr404($slug);
        $uri = self::PLATFORM_NS . $slug;
        abort_if($this->labelOf($uri, 'ex:Platform') === null, 404);

        return response()->json($this->entityShows($request, 'platform_shows_v2_', 'ex:platform', $uri));
    }

    /**
     * /top-rated         -> daftar Top Rated (halaman dari ?page=N)
     * /top-rated/{rank}  -> halaman yang memuat rank itu, kartunya disorot
     * /top-rated?id=tt.. -> (URL lama) redirect 301 ke /top-rated/{rank}
     * Datanya dimuat browser lewat /api/top-rated (lihat topRatedShows()); urutan = ex:rank di graf.
     */
    public function topRated(Request $request, ?int $rank = null)
    {
        $id = (string) $request->query('id', '');
        if ($rank === null && preg_match('/^tt\d+$/', $id)) {
            $rows = $this->select('
                SELECT ?rk WHERE { <' . self::SERIES_NS . $id . '> ex:rank ?rk } LIMIT 1
            ');
            if (isset($rows[0]['rk'])) {
                return redirect()->to(url('/top-rated/' . (int) $rows[0]['rk']), 301);
            }
        }

        $per  = self::TOP_PER;
        $page = max(1, (int) $request->query('page', 1));

        if ($rank !== null) {
            $pos = $this->rankPosition($rank);
            abort_if($pos === null, 404);
            $page = intdiv($pos - 1, $per) + 1;
        }

        return view('top-rated', [
            'shows'   => [],
            'initial' => ['rank' => $rank, 'page' => $page, 'per' => $per],
        ]);
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

            $found = $this->slugByLabel('ex:Keyword', $k);
            if ($found === null) {
                return view('keyword', ['shows' => [], 'keyword' => $k]);
            }

            return redirect()->to(url('/keyword/' . $found), 301);
        }

        self::slugOr404($slug);

        $uri   = self::KEYWORD_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, 404);

        $shows = Cache::remember(
            'keyword_shows_v2_' . $slug,
            3600,
            fn () => $this->cardsWhere("?s schema:keywords <$uri> .")
        );

        return view('keyword', ['shows' => $shows, 'keyword' => $label]);
    }

    // /company/sony-pictures-television -> series yang diproduksi company itu.
    // Dicari lewat IRI organization:{slug} di Fuseki
    public function company(string $slug)
    {
        self::slugOr404($slug);

        $uri   = self::ORG_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, 404);

        $shows = Cache::remember('company_shows_v1_' . $slug, 3600, fn () => $this->cardsLinkedTo('schema:productionCompany', $uri));

        return view('company', ['shows' => $shows, 'company' => $label]);
    }

    // /country/united-states -> series yang berasal dari negara itu (schema:countryOfOrigin).
    // Dicari lewat IRI country:{slug} di Fuseki
    public function country(string $slug)
    {
        self::slugOr404($slug);

        $uri   = self::COUNTRY_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, 404);

        $shows = Cache::remember('country_shows_v1_' . $slug, 3600, fn () => $this->cardsLinkedTo('schema:countryOfOrigin', $uri));

        return view('country', ['shows' => $shows, 'country' => $label]);
    }

    // /language/english -> series yang memakai bahasa itu
    public function language(string $slug)
    {
        self::slugOr404($slug);

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
        self::slugOr404($slug);

        $uri = self::PERSON_NS . $slug;

        // 1) Orangnya ada? (sekaligus mengambil label untuk judul halaman)
        $label = $this->labelOf($uri);
        abort_if($label === null, 404);

        // 2) Series dengan orang ini sebagai aktor dan/atau author, plus field kartu
        $id   = self::ID;
        $rows = $this->select("
            SELECT ?s $id
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
            ORDER BY DESC(xsd:decimal(?ratingValue)) DESC(xsd:integer(?votesValue)) ASC(STR(?s))
        ");

        $shows = array_map(fn ($r) => [
            'id'               => $r['id'],
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
            'person' => $label,
        ]);
    }

    public function detail(string $id)
    {
        abort_unless(preg_match('/^tt\d+$/', $id), 404);

        $uri  = self::SERIES_NS . $id;  // subjek di triplestore
        $imdb = "https://www.imdb.com/title/{$id}/"; // untuk tombol "View on IMDb"

        // Properti series yang dipakai halaman ini saja, dipilih di graf berdasarkan nama lokal predikat.
        // Kunci, slug entitas, label (fallback dari IRI), dan durasi (menit) dihitung di SPARQL.
        $keys = implode(', ', array_map(
            fn ($k) => self::sparqlStr($k),
            array_merge(self::LINKED_KEYS, self::DETAIL_KEYS)
        ));
        $dur  = self::minutesExpr('?o');
        $rows = $this->select("
            SELECT ?key ?val ?lbl ?slug WHERE {
                <$uri> ?p ?o .
                BIND(REPLACE(STR(?p), \"^.*[/#]\", \"\") AS ?key)
                FILTER(?key IN ($keys))
                OPTIONAL { ?o rdfs:label ?ol . }
                BIND(IF(?key = \"duration\", STR(COALESCE($dur, 0)), STR(?o)) AS ?val)
                BIND(COALESCE(STR(?ol), REPLACE(REPLACE(STR(?o), \"^.*[/#]\", \"\"), \"[_-]\", \" \")) AS ?lbl)
                BIND(REPLACE(STR(?o), \"^.*/\", \"\") AS ?slug)
            }
        ");
        abort_if(empty($rows), 404);

        // Rating ada di node terpisah (.../tt0043208/rating)
        $ratingRows = $this->select("
            SELECT ?rating ?votes ?best ?worst WHERE {
                <$uri> schema:aggregateRating ?r .
                ?r schema:ratingValue ?rating .
                OPTIONAL { ?r schema:ratingCount ?votes . }
                OPTIONAL { ?r schema:bestRating ?best . }
                OPTIONAL { ?r schema:worstRating ?worst . }
            } LIMIT 1
        ");

        // Hasil per kategori award (ex:AwardResult -> ex:Award), urut sesuai ex:sortOrder
        $awardRows = $this->select("
            SELECT ?label (COALESCE(xsd:integer(?won0), 0) AS ?won) (COALESCE(xsd:integer(?nom0), 0) AS ?nom)
            WHERE {
                <$uri> ex:awardResult ?r .
                ?r ex:awardCategory ?a .
                ?a rdfs:label ?label .
                OPTIONAL { ?a ex:sortOrder ?ord }
                OPTIONAL { ?r ex:wonCount ?won0 }
                OPTIONAL { ?r ex:nominatedCount ?nom0 }
            }
            ORDER BY ?ord ?label
        ");

        // Penyusunan JSON dari baris hasil query (tanpa logika pemilihan data)
        $show   = ['id' => $id, 'uri' => $imdb];
        $linked = array_fill_keys(self::LINKED_KEYS, []);
        $langs  = [];
        $links  = [];

        foreach ($rows as $r) {
            $key = $r['key'];
            if (in_array($key, self::LINKED_KEYS, true)) {
                $linked[$key][$r['slug']] = $r['lbl'];
            } elseif ($key === 'inLanguage') {
                $langs[] = $r['lbl'];
            } elseif ($key === 'sameAs') { // owl:sameAs & schema:sameAs
                $links[] = $r['val'];
            } elseif ($key === 'duration') {
                $show['duration'] = (int) $r['val'];
            } else {
                $show[$key] = $r['val'];
            }
        }

        $show['inLanguage'] = implode(', ', array_unique($langs));
        foreach (self::LINKED_KEYS as $k) {
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
            'won'       => (int) $r['won'],
            'nominated' => (int) $r['nom'],
        ], $awardRows);

        $rt = $ratingRows[0] ?? [];
        $show['aggregateRating']  = isset($rt['rating']) ? (float) $rt['rating'] : 0;
        $show['interactionCount'] = isset($rt['votes']) ? (int) $rt['votes'] : 0;
        $show['bestRating']       = isset($rt['best']) ? (float) $rt['best'] : 10;
        $show['worstRating']      = isset($rt['worst']) ? (float) $rt['worst'] : 1;

        // Rank = ex:rank dari graf (sama dengan urutan Top Rated); 0 = tidak ada rank
        $show['rank'] = isset($show['rank']) ? (int) $show['rank'] : 0;

        // Similar recommendation (hanya memunculkan 12 series)
        $similar = $this->similarTo($id);

        // 'shows' -> untuk carousel similar
        return view('detail', ['show' => $show, 'shows' => $similar]);
    }
}