<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use App\Services\SparqlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ShowController extends Controller
{
    // Subjek series di TTL: http://example.org/tvseries/series/tt0043208
    private const SERIES_NS = 'http://example.org/tvseries/series/';

    // Field yang hanya dikirim ke browser kalau diminta lewat allShows([...])
    // _g / _a = daftar genre & aktor dalam bentuk array (khusus perhitungan similar & halaman person, tidak pernah dikirim)
    private const OPTIONAL = ['description', 'keywords', 'platform', 'author', 'numberOfEpisodes', 'contentRating', 'status', 'duration', '_g', '_a'];

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

            return array_map(function ($r) use ($kw, $pf, $au) {
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

    public function home()
    {
        return view('home', ['shows' => $this->allShows()]);
    }

    public function search()
    {
        // 'platform' ikut dikirim supaya filter Platform di halaman search bisa bekerja
        return view('search', ['shows' => $this->allShows(['platform'])]);
    }

    public function chat(Request $request, ChatbotService $chatbot)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        $stateKey = 'series_tv_chat_state';
        $response = $chatbot->respond(
            $validated['message'],
            $this->loadShows(),
            (array) $request->session()->get($stateKey, [])
        );
        if (array_key_exists('state', $response)) {
            $request->session()->put($stateKey, $response['state']);
            unset($response['state']);
        } else {
            $request->session()->forget($stateKey);
        }

        return response()->json($response);
    }

    public function genre()
    {
        return view('genre', ['shows' => $this->allShows()]);
    }

    public function platform()
    {
        return view('platform', ['shows' => $this->allShows(['platform'])]);
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

    // /keyword?k=new+mexico  -> hanya series yang punya keyword itu yang dikirim ke browser
    public function keyword(Request $request)
    {
        $k = mb_strtolower(trim((string) $request->query('k', '')));

        $matches = $k === '' ? [] : array_values(array_filter(
            $this->loadShows(),
            fn ($s) => in_array($k, $s['keywords'], true)
        ));

        return view('keyword', [
            'shows'   => $this->slim($matches),
            'keyword' => $k,
        ]);
    }

    // /person?name=Bryan+Cranston -> series yang dibintangi ATAU dibuat orang itu.
    // 'roles' (actor / author) dikirim per series supaya halaman bisa menyaring per peran.
    public function person(Request $request)
    {
        $name   = trim((string) $request->query('name', ''));
        $needle = mb_strtolower($name);
        $lower  = fn (array $names) => array_map('mb_strtolower', $names);

        $matches = [];
        if ($needle !== '') {
            foreach ($this->loadShows() as $s) {
                $roles = [];
                if (in_array($needle, $lower($s['_a']), true))      $roles[] = 'actor';
                if (in_array($needle, $lower($s['author']), true))  $roles[] = 'author';
                if ($roles) {
                    $s['roles'] = $roles;
                    $matches[] = $s;
                }
            }
        }

        return view('person', [
            'shows'  => $this->slim($matches),
            'person' => $name,
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
        $multiString = ['genre', 'countryOfOrigin', 'inLanguage'];
        // dikirim sebagai array (nama bisa mengandung koma)
        // actor & author dipakai sebagai link ke /person
        $multiArray  = ['actor', 'author', 'spokenLanguage', 'platform', 'productionCompany'];
        $single = [
            'name', 'description', 'image', 'award', 'contentRating', 'slogan',
            'startDate', 'endDate', 'numberOfSeasons', 'numberOfEpisodes',
            'status', 'rank', 'tmdbId', 'tvdbId', 'wikidataId', 'imdbId',
            'trailer', 'thumbnailUrl', 'alternateName',
        ];

        $show  = ['id' => $id, 'uri' => $imdb];
        $lists = [];
        $keywords = [];
        $links = [];

        foreach ($rows as $r) {
            $key = preg_replace('/^.*[\/#]/', '', $r['p']);
            $val = $r['o'];
            $lbl = !empty($r['ol']) ? $r['ol'] : self::label($val);

            if (in_array($key, $multiString, true) || in_array($key, $multiArray, true)) {
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
        $show['keywords'] = array_values(array_unique($keywords));
        $show['links']    = array_values(array_unique($links));

        $rt = $ratingRows[0] ?? [];
        $show['aggregateRating']  = isset($rt['rating']) ? (float) $rt['rating'] : 0;
        $show['interactionCount'] = isset($rt['votes']) ? (int) $rt['votes'] : 0;
        $show['bestRating']       = isset($rt['best']) ? (float) $rt['best'] : 10;
        $show['worstRating']      = isset($rt['worst']) ? (float) $rt['worst'] : 1;

        // Similar recommendation ( hanya memnculkan 12 series ) 
        $all = $this->loadShows();
        $cur = null;
        foreach ($all as $s) {
            if ($s['id'] === $id) { $cur = $s; break; }
        }
        $cur ??= [
            '_g'       => array_values(array_filter(array_map('trim', explode(',', $show['genre'] ?? '')))),
            '_a'       => array_values((array) ($show['actor'] ?? [])),
            'keywords' => array_map('mb_strtolower', $show['keywords']),
        ];
        $similar = $this->similarTo($id, $cur, $all);

        // 'shows' -> untuk carousel similar)
        return view('detail', ['show' => $show, 'shows' => $similar]);
    }
}