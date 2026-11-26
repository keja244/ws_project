<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use App\Services\SparqlService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ShowController extends Controller
{
    private const SERIES_NS   = 'http://example.org/tvseries/series/';
    private const PERSON_NS   = 'http://example.org/tvseries/person/';
    private const ORG_NS      = 'http://example.org/tvseries/organization/';
    private const GENRE_NS    = 'http://example.org/tvseries/genre/';
    private const PLATFORM_NS = 'http://example.org/tvseries/platform/';
    private const KEYWORD_NS  = 'http://example.org/tvseries/keyword/';
    private const COUNTRY_NS  = 'http://example.org/tvseries/country/';
    private const LANGUAGE_NS = 'http://example.org/tvseries/language/';

    private const PREFIXES = '
        PREFIX schema: <https://schema.org/>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
        PREFIX owl: <http://www.w3.org/2002/07/owl#>
        PREFIX ex: <http://example.org/tvseries/vocab#>
        PREFIX afn: <http://jena.apache.org/ARQ/function#>
    ';

    private const ID         = '(REPLACE(STR(?s), "^.*/", "") AS ?id)';
    private const BY_RATING  = 'DESC(COALESCE(?rt, 0)) DESC(COALESCE(?votes, 0))';
    private const TIE        = ' ASC(STR(?s))';
    private const CARD_ORDER = 'DESC(xsd:decimal(?ratingValue)) DESC(xsd:integer(?votesValue)) ASC(STR(?s))';

    private const ASIA_SLUGS = ['japan', 'south-korea', 'china', 'india', 'hong-kong', 'singapore'];

    private const HOME_ROWS = [
        ['recommended',  'Recommended by Us',   true],
        ['top-picks',    'Top Picks',           true],
        ['popular',      'Most Popular',        false],
        ['long-running', 'Long-Running Series', false],
        ['awards',       'Award Winning',       false],
        ['asian',        'Top Asian',           false],
    ];

    private const RECOMMENDED_IDS = [
        'tt0903747', 'tt0944947', 'tt4574334', 'tt7366338', 'tt2861424',
        'tt1475582', 'tt0108778', 'tt0106179', 'tt0111161',
    ];

    private const CORE = '
        { SELECT ?s (SAMPLE(?n) AS ?name) (MIN(?y0) AS ?yr)
         (MAX(?ns0) AS ?ns) (SAMPLE(?r0) AS ?rt)
         (SAMPLE(?v0) AS ?votes) (SAMPLE(?rk0) AS ?rank)
          WHERE {
            ?s a schema:TVSeries ; schema:name ?n .
            OPTIONAL { ?s schema:startDate ?sd . }
            BIND(IF(BOUND(?sd) && STRLEN(STR(?sd)) >= 4,
                xsd:integer(SUBSTR(STR(?sd), 1, 4)), ?tanpaTahun) AS ?y0)
            OPTIONAL { ?s schema:numberOfSeasons ?nsr . BIND(xsd:integer(?nsr) AS ?ns0) }
            OPTIONAL { ?s ex:rank ?rkr . BIND(xsd:integer(?rkr) AS ?rk0) }
            OPTIONAL {
                ?s schema:aggregateRating ?ar . ?ar schema:ratingValue ?rr .
                BIND(xsd:decimal(?rr) AS ?r0)
                OPTIONAL { ?ar schema:ratingCount ?vc . BIND(xsd:integer(?vc) AS ?v0) }
            }
          } GROUP BY ?s }
    ';

    private const FEAT_WINS = 'OPTIONAL { ?s ex:winCount ?wins }';

    private const LINKED_KEYS = [
        'actor', 'author', 'productionCompany', 'genre',
        'platform', 'keywords', 'countryOfOrigin', 'spokenLanguage',
    ];

    private const PRED_MAP = [
        ['schema:actor', 'actor'], ['schema:author', 'author'],
        ['schema:productionCompany', 'productionCompany'], ['schema:genre', 'genre'],
        ['ex:platform', 'platform'], ['schema:keywords', 'keywords'],
        ['schema:countryOfOrigin', 'countryOfOrigin'], ['ex:spokenLanguage', 'spokenLanguage'],
        ['schema:name', 'name'], ['schema:description', 'description'], ['schema:image', 'image'],
        ['schema:award', 'award'], ['schema:contentRating', 'contentRating'], ['schema:slogan', 'slogan'],
        ['schema:startDate', 'startDate'], ['schema:endDate', 'endDate'],
        ['schema:numberOfSeasons', 'numberOfSeasons'], ['schema:numberOfEpisodes', 'numberOfEpisodes'],
        ['ex:status', 'status'], ['ex:tmdbId', 'tmdbId'], ['ex:tvdbId', 'tvdbId'],
        ['ex:wikidataId', 'wikidataId'], ['ex:imdbId', 'imdbId'],
        ['schema:trailer', 'trailer'], ['schema:thumbnailUrl', 'thumbnailUrl'],
        ['schema:alternateName', 'alternateName'],
        ['ex:winCount', 'winCount'], ['ex:nominationCount', 'nominationCount'],
        ['ex:rank', 'rank'], ['schema:duration', 'duration'],
        ['schema:url', 'uri'],
        ['schema:sameAs', 'sameAs'], ['owl:sameAs', 'sameAs'],
        ['schema:inLanguage', 'inLanguage'],
    ];

    private const ITEM_SEP = "\x1E";
    private const PART_SEP = "\x1F";

    public function __construct(private SparqlService $sparql) {}

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function cfg(string $key): mixed
    {
        return config("tvseries.{$key}");
    }

    private static function byRank(): string
    {
        $fallback = (int) self::cfg('defaults.rank_fallback');
        return "ASC(COALESCE(?rank, {$fallback})) ASC(STR(?s))";
    }

    private function select(string $body): array
    {
        return $this->sparql->query(self::PREFIXES . $body);
    }

    private static function qkey(string $name, string $query): string
    {
        return $name . '_' . md5(self::PREFIXES . $query);
    }

    private static function labels(?string $concat): array
    {
        return $concat ? explode('|', $concat) : [];
    }

    private static function slugOr404(string $slug): string
    {
        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), Response::HTTP_NOT_FOUND);
        return $slug;
    }

    private static function sparqlStr(string $v): string
    {
        return '"' . strtr($v, [
            '\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t',
        ]) . '"';
    }

    private static function reLit(string $s): string
    {
        return preg_replace('/[.\\\\+*?\[\]^$(){}|\-]/', '\\\\$0', $s) ?? $s;
    }

    private static function rx(string $s, string $prefix = '', string $suffix = ''): string
    {
        return self::sparqlStr($prefix . self::reLit($s) . $suffix);
    }

    private static function minutesExpr(string $v): string
    {
        $e = 'IF(REGEX({S}, "^PT[0-9]+[HM]"), '
            . '(IF(REGEX({S}, "^PT[0-9]+H"), '
            . 'xsd:integer(REPLACE({S}, "^PT([0-9]+)H.*$", "$1")), 0) * 60 '
            . '+ IF(REGEX({S}, "^PT([0-9]+H)?[0-9]+M"), '
            . 'xsd:integer(REPLACE({S}, "^PT([0-9]+H)?([0-9]+)M.*$", "$2")), 0)), '
            . 'xsd:integer(REPLACE({S}, "^[^0-9]*([0-9]+).*$", "$1")))';

        return str_replace('{S}', "STR($v)", $e);
    }

    private static function agg(string $alias, string $pred, string $pattern = '', string $sep = '|', bool $raw = false, bool $lower = false): string
    {
        $v = $raw
            ? 'STR(?o)'
            : 'COALESCE(STR(?l), REPLACE(REPLACE(STR(?o), "^.*[/#]", ""), "[_-]", " "))';
        if ($lower) {
            $v = "LCASE($v)";
        }
        $v     = 'REPLACE(' . $v . ', "^\\\\s+|\\\\s+$", "")';
        $label = $raw ? '' : 'OPTIONAL { ?o rdfs:label ?l }';

        return "OPTIONAL { SELECT ?s (GROUP_CONCAT(DISTINCT $v; separator=\"$sep\") AS ?$alias) "
            . "WHERE { ?s a schema:TVSeries . $pattern ?s $pred ?o . $label FILTER(REGEX(STR(?o), \"[^\\\\s]\")) } GROUP BY ?s }\n";
    }

    private function labelOf(string $iri, ?string $class = null): ?string
    {
        $type = $class ? "<$iri> a $class ." : '';
        $one  = (int) self::cfg('defaults.one');
        $rows = $this->select("SELECT ?label WHERE { $type <$iri> rdfs:label ?label . } LIMIT {$one}");

        return Arr::first($rows)['label'] ?? null;
    }

    private function slugByLabel(string $class, string $label): ?string
    {
        $one  = (int) self::cfg('defaults.one');
        $rows = $this->select('
            SELECT (REPLACE(STR(?e), "^.*/", "") AS ?slug)
            WHERE {
                ?e a ' . $class . ' ; rdfs:label ?l .
                FILTER(REGEX(STR(?l), ' . self::rx($label, '^', '$') . ', "i"))
            } LIMIT ' . $one
        );

        return Arr::first($rows)['slug'] ?? null;
    }

    // ------------------------------------------------------------------
    // External RDF: Wikidata Filming & Setting Locations (/api/locations/{wikidataId})
    // ------------------------------------------------------------------

    public function locationsApi(Request $request, string $wikidataId)
    {
        abort_unless($wikidataId === 'none' || preg_match('/^Q\d+$/', $wikidataId), Response::HTTP_NOT_FOUND);

        $country = trim((string) $request->query('country', ''));
        $country = $country !== '' ? mb_substr($country, 0, 100) : '';

        $cacheKey = 'wikidata_locations_' . $wikidataId . '_' . md5(mb_strtolower($country));
        if (Cache::has($cacheKey)) {
            return response()->json(['locations' => Cache::get($cacheKey)]);
        }

        $limit = max(1, (int) self::cfg('limits.locations'));
        $ttl   = max(60, (int) self::cfg('cache.locations'));
        $locations = [];

        if (preg_match('/^Q\d+$/', $wikidataId)) {
            $sparql = <<<SPARQL
                SELECT DISTINCT ?place ?placeLabel ?coord WHERE {
                    wd:{$wikidataId} wdt:P915|wdt:P840 ?place .
                    ?place wdt:P625 ?coord .
                    OPTIONAL {
                        ?place rdfs:label ?placeLabel .
                        FILTER(LANG(?placeLabel) = 'en')
                    }
                }
                LIMIT {$limit}
            SPARQL;

            foreach ($this->sparql->queryWikidata($sparql) as $r) {
                if (!empty($r['coord']) && preg_match('/Point\(([-0-9.]+)\s+([-0-9.]+)\)/i', $r['coord'], $m)) {
                    $locations[] = [
                        'name' => $r['placeLabel'] ?? basename($r['place']),
                        'uri'  => $r['place'],
                        'type' => 'filming',
                        'lat'  => (float) $m[2],
                        'lng'  => (float) $m[1],
                    ];
                }
            }
        }

        // Fallback ke negara asal jika lokasi spesifik via wikidataId kosong atau tidak ada
        if (!$locations && $country !== '') {
            $safeCountry = self::sparqlStr($country);
            $countrySparql = <<<SPARQL
                SELECT DISTINCT ?place ?placeLabel ?coord WHERE {
                    ?place rdfs:label ?placeLabel ;
                           wdt:P625 ?coord .
                    FILTER(LANG(?placeLabel) = 'en' && LCASE(STR(?placeLabel)) = LCASE({$safeCountry}))
                }
                LIMIT 5
            SPARQL;

            foreach ($this->sparql->queryWikidata($countrySparql) as $r) {
                if (!empty($r['coord']) && preg_match('/Point\(([-0-9.]+)\s+([-0-9.]+)\)/i', $r['coord'], $m)) {
                    $locations[] = [
                        'name' => $r['placeLabel'] ?? $country,
                        'uri'  => $r['place'],
                        'type' => 'country',
                        'lat'  => (float) $m[2],
                        'lng'  => (float) $m[1],
                    ];
                    break;
                }
            }
        }

        if ($locations) {
            Cache::put($cacheKey, $locations, $ttl);
        }

        return response()->json(['locations' => $locations]);
    }

    // ------------------------------------------------------------------
    // Chatbot dataset
    // ------------------------------------------------------------------

    private function showsQuery(): string
    {
        $aggs = self::agg('genres', 'schema:genre')
            . self::agg('actors', 'schema:actor')
            . self::agg('countries', 'schema:countryOfOrigin', '', ', ')
            . self::agg('awards', 'schema:award', '', ', ', true)
            . self::agg('keywords', 'schema:keywords', '', '|', false, true)
            . self::agg('platforms', 'ex:platform')
            . self::agg('authors', 'schema:author')
            . self::agg('companies', 'schema:productionCompany');
        $dur = self::minutesExpr('?duration');
        $id  = self::ID;

        return "
            SELECT ?s $id ?genres ?actors ?countries ?awards ?keywords ?platforms ?authors ?companies
                (REPLACE(COALESCE(?genres, \"\"), \"[|]\", \", \") AS ?genresText)
                (REPLACE(COALESCE(?actors, \"\"), \"[|]\", \", \") AS ?actorsText)
                (COALESCE(?countries, \"\") AS ?countryText)
                (COALESCE(?awards, \"\") AS ?awardText)
                (SAMPLE(?name) AS ?nameValue)
                (SAMPLE(?startDate) AS ?startDateValue)
                (SAMPLE(?endDate) AS ?endDateValue)
                (SAMPLE(?numberOfSeasons) AS ?numberOfSeasonsValue)
                (SAMPLE(?numberOfEpisodes) AS ?numberOfEpisodesValue)
                (COALESCE(SAMPLE(?contentRating), \"\") AS ?contentRatingValue)
                (COALESCE(SAMPLE(?status), \"\") AS ?statusValue)
                (COALESCE(SAMPLE(?dmin), 0) AS ?durationValue)
                (COALESCE(SAMPLE(?image), \"\") AS ?imageValue)
                (COALESCE(SAMPLE(?description), \"\") AS ?descriptionValue)
                (COALESCE(SAMPLE(?rating), 0) AS ?ratingValue)
                (COALESCE(SAMPLE(?votes), 0) AS ?votesValue)
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
        ";
    }

    private function loadShows(): array
    {
        $query = $this->showsQuery();

        return Cache::remember(self::qkey('all_shows', $query), (int) self::cfg('cache.default'), function () use ($query) {
            return array_map(fn ($r) => [
                'id'                => $r['id'],
                'name'              => $r['nameValue'],
                'aggregateRating'   => (float) $r['ratingValue'],
                'interactionCount'  => (int) $r['votesValue'],
                'startDate'         => $r['startDateValue'] ?? null,
                'endDate'           => $r['endDateValue'] ?? null,
                'numberOfSeasons'   => $r['numberOfSeasonsValue'] ?? null,
                'numberOfEpisodes'  => $r['numberOfEpisodesValue'] ?? null,
                'contentRating'     => $r['contentRatingValue'],
                'status'            => $r['statusValue'],
                'duration'          => (int) $r['durationValue'],
                'award'             => $r['awardText'],
                'countryOfOrigin'   => $r['countryText'],
                'image'             => $r['imageValue'],
                'description'       => $r['descriptionValue'],
                'genre'             => $r['genresText'],
                'actor'             => $r['actorsText'],
                'platform'          => self::labels($r['platforms'] ?? null),
                'keywords'          => self::labels($r['keywords'] ?? null),
                'author'            => self::labels($r['authors'] ?? null),
                'productionCompany' => self::labels($r['companies'] ?? null),
                '_g'                => self::labels($r['genres'] ?? null),
                '_a'                => self::labels($r['actors'] ?? null),
            ], $this->select($query));
        });
    }

    // ------------------------------------------------------------------
    // Series Cards
    // ------------------------------------------------------------------

    private function cardsQuery(string $pattern, string $order = self::CARD_ORDER, bool $withDescription = false): string
    {
        $aggs = self::agg('genres', 'schema:genre', $pattern, ', ')
            . self::agg('actors', 'schema:actor', $pattern, ', ')
            . self::agg('countries', 'schema:countryOfOrigin', $pattern, ', ')
            . self::agg('awards', 'schema:award', $pattern, ', ', true);
        $pos     = str_contains($pattern, '?pos') ? '(MIN(?pos) AS ?posValue)' : '';
        $descSel = $withDescription ? '(COALESCE(SAMPLE(?description), "") AS ?descriptionValue)' : '';
        $descOpt = $withDescription ? 'OPTIONAL { ?s schema:description ?description . }' : '';
        $id      = self::ID;

        return "
            SELECT ?s $id ?genres ?actors ?countries ?awards
                (COALESCE(?genres, \"\") AS ?genreText)
                (COALESCE(?actors, \"\") AS ?actorText)
                (COALESCE(?countries, \"\") AS ?countryText)
                (COALESCE(?awards, \"\") AS ?awardText)
                (SAMPLE(?name) AS ?nameValue)
                (SAMPLE(?startDate) AS ?startDateValue)
                (SAMPLE(?endDate) AS ?endDateValue)
                (SAMPLE(?numberOfSeasons) AS ?numberOfSeasonsValue)
                (COALESCE(SAMPLE(?image), \"\") AS ?imageValue)
                (COALESCE(SAMPLE(?rating), 0) AS ?ratingValue)
                (COALESCE(SAMPLE(?votes), 0) AS ?votesValue)
                (COALESCE(SAMPLE(?rank), 0) AS ?rankValue)
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
        ";
    }

    private function cardsWhere(string $pattern, string $order = self::CARD_ORDER, bool $withDescription = false): array
    {
        $rows = $this->select($this->cardsQuery($pattern, $order, $withDescription));

        return array_map(fn ($r) => [
            'id'               => $r['id'],
            'name'             => $r['nameValue'],
            'rank'             => (int) $r['rankValue'],
            'aggregateRating'  => (float) $r['ratingValue'],
            'interactionCount' => (int) $r['votesValue'],
            'startDate'        => $r['startDateValue'] ?? null,
            'endDate'          => $r['endDateValue'] ?? null,
            'numberOfSeasons'  => $r['numberOfSeasonsValue'] ?? null,
            'award'            => $r['awardText'],
            'countryOfOrigin'  => $r['countryText'],
            'image'            => $r['imageValue'],
            'genre'            => $r['genreText'],
            'actor'            => $r['actorText'],
        ] + ($withDescription ? ['description' => $r['descriptionValue']] : []), $rows);
    }

    private function cardsByIris(array $iris, bool $withDescription = false): array
    {
        if (!$iris) {
            return [];
        }
        $one    = (int) self::cfg('defaults.one');
        $values = implode(' ', array_map(
            fn ($iri, $n) => '(<' . $iri . '> ' . ($n + $one) . ')',
            $iris,
            array_keys($iris)
        ));

        return $this->cardsWhere("VALUES (?s ?pos) { $values }", 'ASC(?posValue)', $withDescription);
    }

    // ------------------------------------------------------------------
    // Search, Category, Top Rated
    // ------------------------------------------------------------------

    private static function normText(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if ($d !== false) {
                $s = preg_replace('/\p{Mn}/u', '', $d) ?? $s;
            }
        }
        return mb_strtolower($s);
    }

    private static function searchTokens(string $q): array
    {
        $s = self::normText($q);
        $s = preg_replace('/(\d{4})\s*(?:-|–|—|to|until|sampai)\s*(\d{4})/u', '$1~$2', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9+~ ]+/', ' ', $s) ?? $s;
        $out = [];
        $one = (int) self::cfg('defaults.one');
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
            if ($w !== '' && (strlen($w) > $one || preg_match('/\d/', $w))) {
                $out[] = $w;
            }
        }
        return $out;
    }

    private static function yearRange(string $tok): ?array
    {
        $wExact  = (int) self::cfg('search.weights.year_exact');
        $wDecade = (int) self::cfg('search.weights.year_decade');

        if (preg_match('/^\d{4}$/', $tok)) {
            return [(int) $tok, (int) $tok, $wExact];
        }
        if (preg_match('/^(\d{4})~(\d{4})$/', $tok, $m)) {
            return [(int) $m[1], (int) $m[2], $wExact];
        }
        if (preg_match('/^(\d{4})s$/', $tok, $m)) {
            $start = (int) $m[1];
            return [$start, $start + (int) self::cfg('defaults.best_rating') - (int) self::cfg('defaults.one'), $wExact];
        }
        if (preg_match('/^(\d)0s$/', $tok, $m)) {
            $d   = (int) $m[1];
            $ten = (int) self::cfg('defaults.best_rating');
            $b   = $d >= $wExact ? 1900 + $d * $ten : 2000 + $d * $ten;
            return [$b, $b + $ten - (int) self::cfg('defaults.one'), $wExact];
        }
        if (preg_match('/^\d{3}$/', $tok)) {
            $ten = (int) self::cfg('defaults.best_rating');
            $b   = (int) $tok * $ten;
            return [$b, $b + $ten - (int) self::cfg('defaults.one'), $wDecade];
        }
        return null;
    }

    private function searchParams(Request $request): array
    {
        $zero   = (int) self::cfg('defaults.zero');
        $maxCnt = (int) self::cfg('limits.max_filter_cnt');
        $maxLen = (int) self::cfg('limits.max_query_len');

        $str  = fn (string $k): string => is_string($v = $request->query($k)) ? $v : '';
        $list = fn (string $k): array => array_slice(
            array_values(array_filter(array_map('trim', explode('|', $str($k))), 'strlen')),
            $zero,
            $maxCnt
        );
        $num  = fn (string $k): ?float => is_numeric($str($k)) ? (float) $str($k) : null;

        $in   = $str('in');
        $sort = $str('sort');

        return [
            'q'        => mb_substr(trim($str('q')), $zero, $maxLen),
            'in'       => in_array($in, ['all', 'title', 'genre', 'year', 'cast'], true) ? $in : 'all',
            'genres'   => $list('genre'),
            'from'     => $num('from'),
            'to'       => $num('to'),
            'rating'   => $num('rating'),
            'seasons'  => $num('seasons'),
            'smax'     => $num('smax'),
            'platform' => $list('platform'),
            'country'  => $list('country'),
            'awards'   => $str('awards') === (string) self::cfg('defaults.one'),
            'sort'     => in_array($sort, ['relevance', 'rating', 'votes', 'newest', 'oldest', 'seasons'], true) ? $sort : '',
        ];
    }

    private static function textOf(string $alias, string $pred): string
    {
        return "OPTIONAL { SELECT ?s (GROUP_CONCAT(DISTINCT REPLACE(LCASE(STR(?l)), \"[^a-z0-9+ ]+\", \" \"); separator=\"|\") AS ?{$alias}) "
            . "WHERE { ?s {$pred} ?e . ?e rdfs:label ?l } GROUP BY ?s }\n";
    }

    private static function hasLabel(string $pred, array $values): string
    {
        $in = implode(', ', array_map(fn ($v) => self::sparqlStr(mb_strtolower($v)), $values));
        return "EXISTS { ?s $pred ?e . ?e rdfs:label ?l . FILTER(LCASE(STR(?l)) IN ($in)) }";
    }

    private function searchPlan(array $p): array
    {
        $w    = (array) self::cfg('search.weights');
        $zero = (int) self::cfg('defaults.zero');
        $one  = (int) self::cfg('defaults.one');
        $toks = self::searchTokens($p['q']);
        $in   = $p['in'];
        $all  = $in === 'all';

        $useTitle = $toks && ($all || $in === 'title');
        $useGenre = $toks && ($all || $in === 'genre');
        $useCast  = $toks && ($all || $in === 'cast');
        $useYear  = $toks && ($all || $in === 'year');
        $useMeta  = $toks && $all;

        $features = '';
        $conds    = [];
        $terms    = [];
        $rg       = fn (string $v, string $t, string $pre = '') => 'REGEX(' . $v . ', ' . self::rx($t, $pre) . ', "i")';

        if ($toks) {
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
                $c = [];
                if ($useTitle) {
                    $features .= "BIND(IF(" . $rg('?tl', $tok, '^') . ", {$w['title_prefix']}, "
                        . "IF(" . $rg('?tl', $tok, '(^| )') . ", {$w['title_word']}, "
                        . "IF(" . $rg('?tl', $tok) . ", {$w['title_part']}, {$zero}))) AS ?ti{$i})\n";
                    $c[] = "?ti{$i}";
                }
                if ($useGenre) {
                    $features .= "BIND(IF(" . $rg('?gt', $tok, '(^|[|])') . ", {$w['genre_prefix']}, "
                        . "IF(" . $rg('?gt', $tok) . ", {$w['genre_part']}, {$zero})) AS ?tg{$i})\n";
                    $c[] = "?tg{$i}";
                }
                if ($useCast) {
                    $features .= "BIND(IF(" . $rg('?ct', $tok, '(^|[| ])') . ", {$w['cast_prefix']}, "
                        . "IF(" . $rg('?ct', $tok) . ", {$w['cast_part']}, {$zero})) AS ?tc{$i})\n";
                    $c[] = "?tc{$i}";
                }
                if ($useYear && ($yr = self::yearRange($tok))) {
                    [$lo, $hi, $sc] = $yr;
                    $features .= "BIND(IF(BOUND(?yr) && ?yr >= $lo && ?yr <= $hi, $sc, {$zero}) AS ?ty{$i})\n";
                    $c[] = "?ty{$i}";
                }
                if ($useMeta) {
                    $features .= "BIND(IF(" . $rg('?pt', $tok) . ", {$w['meta']}, {$zero}) AS ?tp{$i})\n";
                    $features .= "BIND(IF(" . $rg('?kt', $tok) . ", {$w['meta']}, {$zero}) AS ?tk{$i})\n";
                    $c[] = "?tp{$i}";
                    $c[] = "?tk{$i}";
                }
                if (!$c) {
                    $c[] = (string) $zero;
                }

                $cur = array_shift($c);
                foreach ($c as $j => $v) {
                    $features .= "BIND(IF($cur > $v, $cur, $v) AS ?m{$i}_{$j})\n";
                    $cur = "?m{$i}_{$j}";
                }
                $terms[] = $cur;
                $conds[] = "$cur > {$zero}";
            }

            if (count($toks) > $one) {
                $ph = implode(' ', $toks);
                if ($useTitle) $terms[] = "IF(" . $rg('?tl', $ph) . ", {$w['phrase_title']}, {$zero})";
                if ($useCast)  $terms[] = "IF(" . $rg('?ct', $ph) . ", {$w['phrase_cast']}, {$zero})";
            }
            $features .= 'BIND(' . implode(' + ', $terms) . " AS ?score)\n";
        }

        $filters = [];
        if ($conds) $filters[] = '(' . implode(' && ', $conds) . ')';
        foreach ($p['genres'] as $g) {
            $filters[] = self::hasLabel('schema:genre', [$g]);
        }
        if ($p['platform'])         $filters[] = self::hasLabel('ex:platform', $p['platform']);
        if ($p['country'])          $filters[] = self::hasLabel('schema:countryOfOrigin', $p['country']);
        if ($p['awards'])           $filters[] = "EXISTS { ?s ex:winCount ?w . FILTER(?w > {$zero}) }";
        if ($p['from'] !== null)    $filters[] = '(BOUND(?yr) && ?yr >= ' . (int) $p['from'] . ')';
        if ($p['to'] !== null)      $filters[] = '(BOUND(?yr) && ?yr <= ' . (int) $p['to'] . ')';
        if ($p['rating'] !== null)  $filters[] = "(COALESCE(?rt, {$zero}) >= " . sprintf('%F', $p['rating']) . ')';
        if ($p['seasons'] !== null) $filters[] = "(COALESCE(?ns, {$zero}) >= " . (int) $p['seasons'] . ')';
        if ($p['smax'] !== null)    $filters[] = "(COALESCE(?ns, {$zero}) > {$zero} && ?ns <= " . (int) $p['smax'] . ')';

        $maxYr = (int) self::cfg('defaults.max_year_fallback');
        $sort  = $p['sort'] ?: ($toks ? 'relevance' : 'rating');
        $order = match ($sort) {
            'relevance' => $toks ? "DESC(?score) DESC(COALESCE(?votes, {$zero}))" : self::BY_RATING,
            'votes'     => "DESC(COALESCE(?votes, {$zero})) DESC(COALESCE(?rt, {$zero}))",
            'newest'    => "DESC(COALESCE(?yr, {$zero})) " . self::BY_RATING,
            'oldest'    => "ASC(COALESCE(?yr, {$maxYr})) " . self::BY_RATING,
            'seasons'   => "DESC(COALESCE(?ns, {$zero})) " . self::BY_RATING,
            default     => self::BY_RATING,
        };

        return [$features, implode(' && ', $filters), $order . self::TIE];
    }

    private static function irisQuery(string $features, string $filters): string
    {
        return 'SELECT ?s WHERE { ' . self::CORE . " $features "
            . ($filters !== '' ? "FILTER($filters)" : '') . ' }';
    }

    private function rankedIris(string $features, string $filters, string $order, int $limit, int $offset = 0): array
    {
        $rows = $this->select(
            self::irisQuery($features, $filters)
            . " ORDER BY $order LIMIT {$limit} OFFSET {$offset}"
        );
        return array_column($rows, 's');
    }

    private function countIris(string $features, string $filters, string $order, ?int $cap): int
    {
        $inner = self::irisQuery($features, $filters)
            . ($cap ? " ORDER BY $order LIMIT {$cap}" : '');
        $rows = $this->select("SELECT (COUNT(*) AS ?n) WHERE { $inner }");

        return (int) (Arr::first($rows)['n'] ?? self::cfg('defaults.zero'));
    }

    private function paged(Request $request, string $cacheKey, array $spec, int $ttl, ?int $defaultPer = null, bool $withDescription = false): array
    {
        [$features, $filters, $order, $cap] = $spec;

        $defaultPer ??= (int) self::cfg('pagination.default_per');
        $minPer  = (int) self::cfg('pagination.min_per');
        $maxPer  = (int) self::cfg('pagination.max_per');
        $minPage = (int) self::cfg('pagination.min_page');
        $maxPage = (int) self::cfg('pagination.max_page');
        $zero    = (int) self::cfg('defaults.zero');

        $per     = min(max((int) $request->query('per', $defaultPer), $minPer), $maxPer);
        $reqPage = min(max((int) $request->query('page', $minPage), $minPage), $maxPage);
        $key     = self::qkey($cacheKey, self::irisQuery($features, $filters) . "|$order|$cap") . "_{$per}_{$reqPage}";

        $res = Cache::remember($key, $ttl, function () use ($features, $filters, $order, $cap, $per, $reqPage, $minPage, $zero) {
            $total = $this->countIris($features, $filters, $order, $cap);
            $pages = max($minPage, (int) ceil($total / $per));
            $page  = min($reqPage, $pages);
            $off   = ($page - $minPage) * $per;
            $iris  = $total === $zero
                ? []
                : $this->rankedIris($features, $filters, $order, max($zero, min($per, $total - $off)), $off);

            return ['total' => $total, 'page' => $page, 'pages' => $pages, 'iris' => $iris];
        });

        return [
            'total' => $res['total'],
            'page'  => $res['page'],
            'pages' => $res['pages'],
            'shows' => $this->cardsByIris($res['iris'], $withDescription),
        ];
    }

    private function categorySpec(string $slug): array
    {
        $zero    = (int) self::cfg('defaults.zero');
        $longMin = (int) self::cfg('defaults.long_running_min');
        $topCap  = (int) self::cfg('limits.recommended');

        return match ($slug) {
            'top-picks'    => ['', '', self::byRank(), $topCap],
            'popular'      => ['', '', "DESC(COALESCE(?votes, {$zero})) DESC(COALESCE(?rt, {$zero}))" . self::TIE, null],
            'long-running' => ['', "COALESCE(?ns, {$zero}) >= {$longMin}", "DESC(COALESCE(?ns, {$zero})) " . self::BY_RATING . self::TIE, null],
            'awards'       => [self::FEAT_WINS, "COALESCE(?wins, {$zero}) > {$zero}", "DESC(COALESCE(?wins, {$zero})) " . self::BY_RATING . self::TIE, null],
            default        => ['', 'EXISTS { ?s schema:countryOfOrigin ?ac . FILTER(?ac IN ('
                . implode(', ', array_map(fn ($s) => '<' . self::COUNTRY_NS . $s . '>', self::ASIA_SLUGS))
                . ')) }', self::BY_RATING . self::TIE, null],
        };
    }

    private function searchFacets(): array
    {
        $minYr  = (int) self::cfg('defaults.min_year');
        $countQ = fn (string $pattern, string $label): string => "
            SELECT ?name (COUNT(DISTINCT ?s) AS ?n)
            WHERE { ?s a schema:TVSeries . $pattern BIND($label AS ?name) }
            GROUP BY ?name
            ORDER BY DESC(?n) ASC(?name)
        ";

        $queries = [
            'genres'    => $countQ('?s schema:genre ?g . OPTIONAL { ?g rdfs:label ?gl }', 'STR(COALESCE(?gl, REPLACE(STR(?g), "^.*[/#]", "")))'),
            'platforms' => $countQ('?s ex:platform ?p . OPTIONAL { ?p rdfs:label ?pl }', 'STR(COALESCE(?pl, REPLACE(STR(?p), "^.*[/#]", "")))'),
            'countries' => $countQ('?s schema:countryOfOrigin ?c . OPTIONAL { ?c rdfs:label ?cl }', 'STR(COALESCE(?cl, REPLACE(REPLACE(STR(?c), "^.*[/#]", ""), "_", " ")))'),
            'years'     => "
                SELECT (COALESCE(MIN(?y), {$minYr}) AS ?mn) (COALESCE(MAX(?y), YEAR(NOW())) AS ?mx)
                WHERE {
                    ?s a schema:TVSeries ; schema:startDate ?sd .
                    BIND(IF(STRLEN(STR(?sd)) >= 4, xsd:integer(SUBSTR(STR(?sd), 1, 4)), ?tanpaTahun) AS ?y)
                    FILTER(BOUND(?y))
                }
            ",
        ];

        return Cache::remember(self::qkey('search_facets', implode('|', $queries)), (int) self::cfg('cache.default'), function () use ($queries) {
            $count = fn (string $q): array => array_map(
                fn ($r) => ['name' => $r['name'], 'n' => (int) $r['n']],
                $this->select($q)
            );
            $years = Arr::first($this->select($queries['years']));

            return [
                'genres'    => $count($queries['genres']),
                'platforms' => $count($queries['platforms']),
                'countries' => $count($queries['countries']),
                'minYear'   => (int) $years['mn'],
                'maxYear'   => (int) $years['mx'],
            ];
        });
    }

    public function searchApi(Request $request)
    {
        $p    = $this->searchParams($request);
        $plan = $this->searchPlan($p);
        $key  = 'search_' . md5(implode('|', $plan));

        return response()->json($this->paged($request, $key, [...$plan, null], (int) self::cfg('cache.search')));
    }

    private function entitySearch(Request $request, string $class, string $link): array
    {
        $zero = (int) self::cfg('defaults.zero');
        $q    = mb_substr(trim((string) $request->query('q', '')), $zero, (int) self::cfg('limits.max_short_q'));
        if ($q === '') {
            return [];
        }

        $per  = min(max((int) $request->query('per', self::cfg('pagination.entity_per')), (int) self::cfg('pagination.min_per')), (int) self::cfg('pagination.max_per'));
        $rows = $this->select('
            SELECT (REPLACE(STR(?e), "^.*/", "") AS ?slug)
                (SAMPLE(?l) AS ?name)
                (COUNT(DISTINCT ?s) AS ?n)
            WHERE {
                { SELECT ?e ?l WHERE {
                    ?e a ' . $class . ' ; rdfs:label ?l .
                    FILTER(REGEX(STR(?l), ' . self::rx($q) . ', "i"))
                } }
                ' . $link . '
                ?s a schema:TVSeries .
            }
            GROUP BY ?e
            ORDER BY DESC(?n) ASC(LCASE(STR(?name)))
            LIMIT ' . $per
        );

        return array_map(fn ($r) => [
            'slug'  => $r['slug'],
            'name'  => $r['name'],
            'count' => (int) $r['n'],
        ], $rows);
    }

    public function peopleApi(Request $request)
    {
        return response()->json($this->entitySearch(
            $request,
            'schema:Person',
            '{ ?s schema:actor ?e } UNION { ?s schema:author ?e }'
        ));
    }

    public function keywordsApi(Request $request)
    {
        return response()->json($this->entitySearch($request, 'ex:Keyword', '?s schema:keywords ?e .'));
    }

    public function quickSearchApi(Request $request)
    {
        $zero = (int) self::cfg('defaults.zero');
        $q    = mb_substr(trim((string) $request->query('q', '')), $zero, (int) self::cfg('limits.max_short_q'));
        if ($q === '') {
            return response()->json([]);
        }

        $per  = min(max((int) $request->query('per', self::cfg('pagination.quick_per')), (int) self::cfg('pagination.min_per')), (int) self::cfg('pagination.max_per'));
        $rows = $this->select('
            SELECT ?s (MIN(STR(?name)) AS ?nm)
            WHERE {
                ?s a schema:TVSeries ; schema:name ?name .
                BIND(' . self::rx($q) . ' AS ?kata)
                FILTER(
                    REGEX(?name, ?kata, "i") ||
                    EXISTS { ?s schema:alternateName ?alt . FILTER(REGEX(STR(?alt), ?kata, "i")) } ||
                    EXISTS { ?s schema:genre ?g . ?g rdfs:label ?genre . FILTER(REGEX(STR(?genre), ?kata, "i")) } ||
                    EXISTS { ?s ex:platform ?pf . ?pf rdfs:label ?platform . FILTER(REGEX(STR(?platform), ?kata, "i")) } ||
                    EXISTS { ?s schema:countryOfOrigin ?c . ?c rdfs:label ?country . FILTER(REGEX(STR(?country), ?kata, "i")) }
                )
            }
            GROUP BY ?s
            ORDER BY ?nm
            LIMIT ' . $per
        );

        return response()->json($this->cardsByIris(array_column($rows, 's')));
    }

    public function categoryShows(Request $request, string $slug)
    {
        abort_unless(in_array($slug, ['top-picks', 'popular', 'long-running', 'awards', 'asian'], true), Response::HTTP_NOT_FOUND);

        return response()->json($this->paged($request, 'category_' . $slug, $this->categorySpec($slug), (int) self::cfg('cache.default')));
    }

    public function topRatedShows(Request $request)
    {
        return response()->json($this->paged(
            $request,
            'top_rated',
            ['', 'BOUND(?rank)', self::byRank(), null],
            (int) self::cfg('cache.default'),
            (int) self::cfg('pagination.top_rated'),
            true
        ));
    }

    private function rankPage(int $rank, int $per): ?int
    {
        $zero = (int) self::cfg('defaults.zero');
        $one  = (int) self::cfg('defaults.one');
        $sql  = "
            SELECT (xsd:integer(FLOOR(SUM(IF(?rk < {$rank}, {$one}, {$zero})) / {$per})) + {$one} AS ?page)
                   (SUM(IF(?rk = {$rank}, {$one}, {$zero})) AS ?hit)
            WHERE {
                ?s a schema:TVSeries ; ex:rank ?rkr .
                BIND(xsd:integer(?rkr) AS ?rk)
                FILTER(?rk <= {$rank})
            }
        ";

        return Cache::remember(self::qkey("rank_page_{$rank}_{$per}", $sql), (int) self::cfg('cache.default'), function () use ($sql, $zero, $one) {
            $first = Arr::first($this->select($sql));
            if ((int) ($first['hit'] ?? $zero) === $zero) {
                return null;
            }
            return (int) ($first['page'] ?? $one);
        });
    }

    private function similarTo(string $id): array
    {
        $uri   = self::SERIES_NS . $id;
        $limit = (int) self::cfg('limits.similar');
        $s     = (array) self::cfg('similarity');
        $zero  = (int) self::cfg('defaults.zero');
        $one   = (int) self::cfg('defaults.one');

        $rows = $this->select("
            SELECT ?s (({$s['genre_weight']}*?genreSim + {$s['kw_weight']}*?kwSim + {$s['cast_weight']}*?castSim + ?rt/{$s['rating_div']}) AS ?score)
            WHERE {
                ?s a schema:TVSeries .
                FILTER(?s != <$uri>)

                { SELECT (COUNT(DISTINCT ?g0) AS ?nG) WHERE { <$uri> schema:genre ?g0 } }
                { SELECT (COUNT(DISTINCT ?k0) AS ?nK) WHERE { <$uri> schema:keywords ?k0 } }

                OPTIONAL { SELECT ?s (COUNT(DISTINCT ?g2) AS ?gT) (COUNT(?gh) AS ?gS)
                    WHERE { ?s schema:genre ?g2 . OPTIONAL { <$uri> schema:genre ?g2 . BIND({$one} AS ?gh) } }
                    GROUP BY ?s }
                OPTIONAL { SELECT ?s (COUNT(DISTINCT ?k2) AS ?kT) (COUNT(?kh) AS ?kS)
                    WHERE { ?s schema:keywords ?k2 . OPTIONAL { <$uri> schema:keywords ?k2 . BIND({$one} AS ?kh) } }
                    GROUP BY ?s }
                OPTIONAL { SELECT ?s (COUNT(DISTINCT ?a1) AS ?aS) WHERE {
                    <$uri> schema:actor ?a1 . ?s schema:actor ?a1 . FILTER(?s != <$uri>) } GROUP BY ?s }
                OPTIONAL { ?s schema:aggregateRating/schema:ratingValue ?ratingRaw }

                BIND(COALESCE(?gS, {$zero}) AS ?gc)
                BIND(COALESCE(?kS, {$zero}) AS ?kc)
                BIND(COALESCE(?aS, {$zero}) AS ?ac)
                FILTER(?gc + ?kc + ?ac > {$zero})

                BIND(?nG + COALESCE(?gT, {$zero}) - ?gc AS ?uni)
                BIND(IF(?uni > {$zero}, ?gc / ?uni, {$zero}) AS ?genreSim)
                BIND(afn:sqrt(?nK * IF(COALESCE(?kT, {$zero}) > {$one}, ?kT, {$one})) AS ?kwDen)
                BIND(IF(?kc > {$zero} && ?kwDen > {$zero}, ?kc / ?kwDen, {$zero}) AS ?kwSim)
                BIND(IF(?ac > {$s['cast_cap']}, {$s['cast_cap']}, ?ac) / {$s['cast_cap']} AS ?castSim)
                BIND(COALESCE(xsd:decimal(?ratingRaw), {$zero}) AS ?rt)
            }
            ORDER BY DESC(?score) ASC(STR(?s))
            LIMIT {$limit}
        ");

        return $this->cardsByIris(array_column($rows, 's'));
    }

    // ------------------------------------------------------------------
    // Home
    // ------------------------------------------------------------------

    private function categoryCards(string $slug, int $limit): array
    {
        [$features, $filters, $order, $cap] = $this->categorySpec($slug);
        $limit = $cap ? min($cap, $limit) : $limit;

        return $this->cardsByIris($this->rankedIris($features, $filters, $order, $limit));
    }

    private function recommendedCards(): array
    {
        $limit = (int) self::cfg('limits.recommended');
        $zero  = (int) self::cfg('defaults.zero');
        $ids   = array_values(array_unique(array_filter(
            self::RECOMMENDED_IDS,
            fn ($i) => is_string($i) && preg_match('/^tt\d+$/', $i)
        )));

        if ($ids) {
            return $this->cardsByIris(array_map(fn ($i) => self::SERIES_NS . $i, array_slice($ids, $zero, $limit)));
        }

        $minRt = (int) self::cfg('defaults.recommended_rating');
        $day   = 'REPLACE(STR(NOW()), "^([0-9]{4})-([0-9]{2})-([0-9]{2}).*$", "$1$2$3")';

        return $this->cardsByIris($this->rankedIris(
            '',
            "COALESCE(?rt, {$zero}) >= {$minRt}",
            'ASC(SHA1(CONCAT(STR(?s), ' . $day . ')))' . self::TIE,
            $limit
        ));
    }

    private function homeRows(): array
    {
        $out   = [];
        $limit = (int) self::cfg('limits.home_row');
        foreach (self::HOME_ROWS as [$slug, $title, $fixed]) {
            $shows = $slug === 'recommended'
                ? $this->recommendedCards()
                : $this->categoryCards($slug, $limit);
            if ($shows) {
                $out[] = ['slug' => $slug, 'title' => $title, 'fixed' => $fixed, 'shows' => $shows];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public function home()
    {
        $sig = self::CORE . json_encode([
            self::HOME_ROWS, self::RECOMMENDED_IDS, self::cfg('limits.home_row'),
            array_map(fn ($r) => Arr::first($r) === 'recommended' ? '' : $this->categorySpec(Arr::first($r)), self::HOME_ROWS),
        ]);
        $rows = Cache::remember(self::qkey('home_rows_' . date('Ymd'), $sig), (int) self::cfg('cache.default'), fn () => $this->homeRows());

        return view('home', ['shows' => [], 'rows' => $rows]);
    }

    public function search()
    {
        return view('search', ['shows' => [], 'facets' => $this->searchFacets()]);
    }

    public function chat(Request $request, ChatbotService $chatbot)
    {
        $maxMsg    = (int) self::cfg('chat.max_message');
        $validated = $request->validate([
            'message'        => ['required', 'string', "max:{$maxMsg}"],
            'conversationId' => ['nullable', 'uuid'],
        ]);

        $conversationId = $validated['conversationId'] ?? 'legacy';
        $stateKey       = 'series_tv_chat_state_' . hash('sha256', strtolower($conversationId));
        $activityKey    = $stateKey . '_last_activity';
        $lastActivity   = $request->session()->get($activityKey);

        if (!is_numeric($lastActivity) || now()->timestamp - (int) $lastActivity >= (int) self::cfg('chat.session_ttl')) {
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
        if (!empty($response['failedQuery'])) {
            $query = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $validated['message']) ?? $validated['message'];
            $query = preg_replace('/(?<!\d)\+?\d[\d\s().-]{7,}\d(?!\d)/u', '[number]', $query) ?? $query;
            Log::info('chatbot.failed_query', [
                'session' => hash_hmac('sha256', $request->session()->getId(), (string) config('app.key')),
                'query'   => mb_substr($query, (int) self::cfg('defaults.zero'), $maxMsg),
            ]);
            unset($response['failedQuery']);
        }

        return response()->json($response);
    }

    public function chatFeedback(Request $request)
    {
        $validated = $request->validate([
            'responseId' => ['required', 'uuid'],
            'helpful'    => ['required', 'boolean'],
            'reason'     => ['nullable', 'in:irrelevant,metadata,misunderstood'],
        ]);
        $helpful = filter_var($validated['helpful'], FILTER_VALIDATE_BOOLEAN);
        if (!$helpful && empty($validated['reason'])) {
            return response()->json(['message' => 'Pilih alasan singkat untuk feedback ini.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        Log::info('chatbot.feedback', [
            'session'     => hash_hmac('sha256', $request->session()->getId(), (string) config('app.key')),
            'response_id' => $validated['responseId'],
            'helpful'     => $helpful,
            'reason'      => $helpful ? null : $validated['reason'],
        ]);

        return response()->json(['message' => 'Terima kasih atas feedback-mu.']);
    }

    private function entityTiles(string $cacheKey, string $class, string $pred, string $order): array
    {
        $sql = "
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
        ";

        return Cache::remember(self::qkey($cacheKey, $sql), (int) self::cfg('cache.default'), function () use ($sql) {
            return array_map(fn ($r) => [
                'slug'  => $r['slug'],
                'name'  => $r['name'],
                'count' => (int) $r['n'],
                'cover' => $r['cover'] ?? '',
            ], $this->select($sql));
        });
    }

    private function genreTiles(): array
    {
        return $this->entityTiles('genre_tiles', 'ex:Genre', 'schema:genre', 'ASC(LCASE(?name))');
    }

    private function platformTiles(): array
    {
        return $this->entityTiles('platform_tiles', 'ex:Platform', 'ex:platform', 'DESC(?n) ASC(LCASE(?name))');
    }

    private function entityShows(Request $request, string $cachePrefix, string $pred, string $iri): array
    {
        $zero = (int) self::cfg('defaults.zero');
        $q    = mb_strtolower(mb_substr(trim((string) $request->query('q', '')), $zero, (int) self::cfg('limits.max_short_q')));
        $sort = (string) $request->query('sort', 'rating');
        $sort = in_array($sort, ['rating', 'votes', 'newest', 'seasons'], true) ? $sort : 'rating';

        $order = match ($sort) {
            'votes'   => "DESC(COALESCE(?votes, {$zero})) DESC(COALESCE(?rt, {$zero}))",
            'newest'  => "DESC(COALESCE(?yr, {$zero})) " . self::BY_RATING,
            'seasons' => "DESC(COALESCE(?ns, {$zero})) " . self::BY_RATING,
            default   => self::BY_RATING,
        } . self::TIE;

        $features = "?s $pred <$iri> .\n";
        $filters  = $q !== '' ? 'REGEX(STR(?name), ' . self::rx($q) . ', "i")' : '';
        $key      = $cachePrefix . md5("$pred|$iri|$q|$sort");

        return $this->paged($request, $key, [$features, $filters, $order, null], (int) self::cfg('cache.default'));
    }

    public function genre(Request $request, ?string $slug = null)
    {
        if ($slug === null) {
            $legacy = trim((string) $request->query('genre', ''));
            if ($legacy !== '' && ($found = $this->slugByLabel('ex:Genre', $legacy)) !== null) {
                $rest = $request->except('genre');
                return redirect()->to(
                    url('/genre/' . $found) . ($rest ? '?' . http_build_query($rest) : ''),
                    Response::HTTP_MOVED_PERMANENTLY
                );
            }
        } else {
            self::slugOr404($slug);
            abort_if($this->labelOf(self::GENRE_NS . $slug, 'ex:Genre') === null, Response::HTTP_NOT_FOUND);
        }

        return view('genre', ['tiles' => $this->genreTiles(), 'initial' => $slug]);
    }

    public function genreShows(Request $request, string $slug)
    {
        self::slugOr404($slug);
        $uri = self::GENRE_NS . $slug;
        abort_if($this->labelOf($uri, 'ex:Genre') === null, Response::HTTP_NOT_FOUND);

        return response()->json($this->entityShows($request, 'genre_shows_', 'schema:genre', $uri));
    }

    public function platform(Request $request, ?string $slug = null)
    {
        if ($slug === null) {
            $legacy = trim((string) $request->query('platform', ''));
            if ($legacy !== '' && ($found = $this->slugByLabel('ex:Platform', $legacy)) !== null) {
                $rest = $request->except('platform');
                return redirect()->to(
                    url('/platform/' . $found) . ($rest ? '?' . http_build_query($rest) : ''),
                    Response::HTTP_MOVED_PERMANENTLY
                );
            }
        } else {
            self::slugOr404($slug);
            abort_if($this->labelOf(self::PLATFORM_NS . $slug, 'ex:Platform') === null, Response::HTTP_NOT_FOUND);
        }

        return view('platform', ['tiles' => $this->platformTiles(), 'initial' => $slug]);
    }

    public function platformShows(Request $request, string $slug)
    {
        self::slugOr404($slug);
        $uri = self::PLATFORM_NS . $slug;
        abort_if($this->labelOf($uri, 'ex:Platform') === null, Response::HTTP_NOT_FOUND);

        return response()->json($this->entityShows($request, 'platform_shows_', 'ex:platform', $uri));
    }

    public function topRated(Request $request, ?int $rank = null)
    {
        $id  = (string) $request->query('id', '');
        $one = (int) self::cfg('defaults.one');

        if ($rank === null && preg_match('/^tt\d+$/', $id)) {
            $rows  = $this->select('SELECT ?rk WHERE { <' . self::SERIES_NS . $id . "> ex:rank ?rk } LIMIT {$one}");
            $first = Arr::first($rows);
            if (isset($first['rk'])) {
                return redirect()->to(url('/top-rated/' . (int) $first['rk']), Response::HTTP_MOVED_PERMANENTLY);
            }
        }

        $per  = (int) self::cfg('pagination.top_rated');
        $page = max($one, (int) $request->query('page', $one));

        if ($rank !== null) {
            $page = $this->rankPage($rank, $per);
            abort_if($page === null, Response::HTTP_NOT_FOUND);
        }

        return view('top-rated', [
            'shows'   => [],
            'initial' => ['rank' => $rank, 'page' => $page, 'per' => $per],
        ]);
    }

    public function category(string $slug)
    {
        abort_unless(in_array($slug, ['top-picks', 'popular', 'long-running', 'awards', 'asian'], true), Response::HTTP_NOT_FOUND);

        return view('category', ['shows' => [], 'slug' => $slug]);
    }

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
            return redirect()->to(url('/keyword/' . $found), Response::HTTP_MOVED_PERMANENTLY);
        }

        self::slugOr404($slug);
        $uri   = self::KEYWORD_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, Response::HTTP_NOT_FOUND);

        $pattern = "?s schema:keywords <$uri> .";
        $shows   = Cache::remember(
            self::qkey('keyword_shows', $this->cardsQuery($pattern)),
            (int) self::cfg('cache.default'),
            fn () => $this->cardsWhere($pattern)
        );

        return view('keyword', ['shows' => $shows, 'keyword' => $label]);
    }

    public function company(string $slug)
    {
        self::slugOr404($slug);
        $uri   = self::ORG_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, Response::HTTP_NOT_FOUND);

        $pattern = "?s schema:productionCompany <$uri> .";
        $shows   = Cache::remember(
            self::qkey('company_shows', $this->cardsQuery($pattern)),
            (int) self::cfg('cache.default'),
            fn () => $this->cardsWhere($pattern)
        );

        return view('company', ['shows' => $shows, 'company' => $label]);
    }

    public function country(string $slug)
    {
        self::slugOr404($slug);
        $uri   = self::COUNTRY_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, Response::HTTP_NOT_FOUND);

        $pattern = "?s schema:countryOfOrigin <$uri> .";
        $shows   = Cache::remember(
            self::qkey('country_shows', $this->cardsQuery($pattern)),
            (int) self::cfg('cache.default'),
            fn () => $this->cardsWhere($pattern)
        );

        return view('country', ['shows' => $shows, 'country' => $label]);
    }

    public function language(string $slug)
    {
        self::slugOr404($slug);
        $uri   = self::LANGUAGE_NS . $slug;
        $label = $this->labelOf($uri);
        abort_if($label === null, Response::HTTP_NOT_FOUND);

        $pattern = "?s ex:spokenLanguage <$uri> .";
        $shows   = Cache::remember(
            self::qkey('language_shows', $this->cardsQuery($pattern)),
            (int) self::cfg('cache.default'),
            fn () => $this->cardsWhere($pattern)
        );

        return view('language', ['shows' => $shows, 'language' => $label]);
    }

    // Merged into a single SPARQL query (person label + shows)
    public function person(string $slug)
    {
        self::slugOr404($slug);
        $uri  = self::PERSON_NS . $slug;
        $id   = self::ID;
        $zero = (int) self::cfg('defaults.zero');

        $rows = $this->select("
            SELECT (SAMPLE(?pl) AS ?personLabel) ?s $id
                (SAMPLE(?name) AS ?nameValue)
                (SAMPLE(?startDate) AS ?startDateValue)
                (SAMPLE(?endDate) AS ?endDateValue)
                (COALESCE(SAMPLE(?image), \"\") AS ?imageValue)
                (COALESCE(SAMPLE(?rating), {$zero}) AS ?ratingValue)
                (COALESCE(SAMPLE(?votes), {$zero}) AS ?votesValue)
                (GROUP_CONCAT(DISTINCT ?role; separator='|') AS ?roles)
            WHERE {
                <$uri> rdfs:label ?pl .
                OPTIONAL {
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
            }
            GROUP BY ?s
            ORDER BY DESC(xsd:decimal(?ratingValue)) DESC(xsd:integer(?votesValue)) ASC(STR(?s))
        ");
        abort_if(empty($rows), Response::HTTP_NOT_FOUND);

        $label = Arr::first($rows)['personLabel'];
        $shows = array_values(array_filter(array_map(fn ($r) => isset($r['id']) ? [
            'id'               => $r['id'],
            'name'             => $r['nameValue'],
            'aggregateRating'  => (float) $r['ratingValue'],
            'interactionCount' => (int) $r['votesValue'],
            'startDate'        => $r['startDateValue'] ?? null,
            'endDate'          => $r['endDateValue'] ?? null,
            'image'            => $r['imageValue'],
            'roles'            => self::labels($r['roles'] ?? null),
        ] : null, $rows)));

        return view('person', [
            'shows'  => $shows,
            'person' => $label,
        ]);
    }

    public function detail(string $id)
    {
        abort_unless(preg_match('/^tt\d+$/', $id), Response::HTTP_NOT_FOUND);

        $uri      = self::SERIES_NS . $id;
        $zero     = (int) self::cfg('defaults.zero');
        $one      = (int) self::cfg('defaults.one');
        $best     = (int) self::cfg('defaults.best_rating');
        $worst    = (int) self::cfg('defaults.worst_rating');
        $rs       = '\u001E';
        $us       = '\u001F';
        $values   = implode(' ', array_map(fn ($m) => "({$m[$zero]} " . self::sparqlStr($m[$one]) . ')', self::PRED_MAP));
        $linkedIn = implode(', ', array_map(fn ($k) => self::sparqlStr($k), self::LINKED_KEYS));
        $dur      = self::minutesExpr('?o');

        $rows = $this->select("
            SELECT ?key (GROUP_CONCAT(DISTINCT ?item; separator=\"$rs\") AS ?vals)
            WHERE {
                VALUES (?p ?key) { $values }
                <$uri> ?p ?o .
                OPTIONAL { ?o rdfs:label ?ol . }
                BIND(IF(?key = \"duration\", STR(COALESCE($dur, {$zero})), STR(?o)) AS ?val)
                BIND(COALESCE(STR(?ol), REPLACE(REPLACE(STR(?o), \"^.*[/#]\", \"\"), \"[_-]\", \" \")) AS ?lbl)
                BIND(REPLACE(STR(?o), \"^.*/\", \"\") AS ?slug)
                BIND(IF(?key IN ($linkedIn), CONCAT(?slug, \"$us\", ?lbl),
                        IF(?key = \"inLanguage\", ?lbl, ?val)) AS ?item)
            }
            GROUP BY ?key
        ");
        abort_if(empty($rows), Response::HTTP_NOT_FOUND);

        $ratingRows = $this->select("
            SELECT (COALESCE(xsd:decimal(?r0), {$zero}) AS ?rating)
                   (COALESCE(xsd:integer(?v0), {$zero}) AS ?votes)
                   (COALESCE(xsd:decimal(?b0), {$best}) AS ?best)
                   (COALESCE(xsd:decimal(?w0), {$worst}) AS ?worst)
            WHERE {
                OPTIONAL {
                    <$uri> schema:aggregateRating ?r .
                    ?r schema:ratingValue ?r0 .
                    OPTIONAL { ?r schema:ratingCount ?v0 . }
                    OPTIONAL { ?r schema:bestRating ?b0 . }
                    OPTIONAL { ?r schema:worstRating ?w0 . }
                }
            } LIMIT {$one}
        ");

        $awardRows = $this->select("
            SELECT ?label (COALESCE(xsd:integer(?won0), {$zero}) AS ?won) (COALESCE(xsd:integer(?nom0), {$zero}) AS ?nom)
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

        $show   = ['id' => $id, 'uri' => "https://www.imdb.com/title/{$id}/", 'inLanguage' => '', 'links' => []];
        $linked = array_fill_keys(self::LINKED_KEYS, []);
        $pair   = (int) self::cfg('defaults.long_running_min');

        foreach ($rows as $r) {
            $key   = $r['key'];
            $items = explode(self::ITEM_SEP, (string) $r['vals']);
            if (in_array($key, self::LINKED_KEYS, true)) {
                foreach ($items as $item) {
                    [$slug, $name] = array_pad(explode(self::PART_SEP, $item, $pair), $pair, '');
                    $linked[$key][$slug] = $name;
                }
            } elseif ($key === 'inLanguage') {
                $show['inLanguage'] = implode(', ', $items);
            } elseif ($key === 'sameAs') {
                $show['links'] = $items;
            } elseif ($key === 'duration') {
                $show['duration'] = (int) Arr::first($items);
            } else {
                $show[$key] = Arr::first($items);
            }
        }

        foreach (self::LINKED_KEYS as $k) {
            $show[$k] = [];
            foreach ($linked[$k] as $slug => $name) {
                $show[$k][] = ['slug' => (string) $slug, 'name' => $name];
            }
        }

        $show['winCount']        = isset($show['winCount']) ? (int) $show['winCount'] : null;
        $show['nominationCount'] = isset($show['nominationCount']) ? (int) $show['nominationCount'] : null;
        $show['awardResults']    = array_map(fn ($r) => [
            'name'      => $r['label'],
            'won'       => (int) $r['won'],
            'nominated' => (int) $r['nom'],
        ], $awardRows);

        $rt                       = Arr::first($ratingRows) ?? [];
        $show['aggregateRating']  = (float) ($rt['rating'] ?? $zero);
        $show['interactionCount'] = (int) ($rt['votes'] ?? $zero);
        $show['bestRating']       = (float) ($rt['best'] ?? $best);
        $show['worstRating']      = (float) ($rt['worst'] ?? $worst);
        $show['rank']             = isset($show['rank']) ? (int) $show['rank'] : $zero;

        return view('detail', ['show' => $show, 'shows' => $this->similarTo($id)]);
    }
}