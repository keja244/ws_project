<?php

namespace App\Http\Controllers;

use App\Services\SparqlService;
use Illuminate\Support\Facades\Cache;

class ShowController extends Controller
{
    public function __construct(private SparqlService $sparql) {}

    // "http://example.org/imdb/genre/Sci-Fi" / "person/Aaron_Paul" -> "Aaron Paul"
    private static function label(string $uri): string
    {
        return str_replace('_', ' ', urldecode(basename(rtrim($uri, '/'))));
    }

    private static function labels(?string $concat): array
    {
        if (!$concat) return [];
        return array_values(array_unique(array_map([self::class, 'label'], explode('|', $concat))));
    }

    // "PT49M" -> 49
    private static function minutes(?string $iso): int
    {
        if (!$iso || !preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?/', $iso, $m)) return 0;
        return ((int) ($m[1] ?? 0)) * 60 + (int) ($m[2] ?? 0);
    }

    private function allShows(bool $withDescription = false): array
    {
        $all = Cache::remember('all_shows_v3', 3600, function () {
            $rows = $this->sparql->query('
                PREFIX schema: <https://schema.org/>
                SELECT ?s ?name ?startDate ?endDate ?numberOfSeasons ?award ?image ?description ?rating ?votes
                    (GROUP_CONCAT(DISTINCT STR(?g); separator="|") AS ?genres)
                    (GROUP_CONCAT(DISTINCT STR(?a); separator="|") AS ?actors)
                    (GROUP_CONCAT(DISTINCT STR(?c); separator="|") AS ?countries)
                WHERE {
                ?s a schema:TVSeries ; schema:name ?name .
                OPTIONAL { ?s schema:startDate ?startDate . }
                OPTIONAL { ?s schema:endDate ?endDate . }
                OPTIONAL { ?s schema:numberOfSeasons ?numberOfSeasons . }
                OPTIONAL { ?s schema:award ?award . }
                OPTIONAL { ?s schema:image ?image . }
                OPTIONAL { ?s schema:description ?description . }
                OPTIONAL { ?s schema:genre ?g . }
                OPTIONAL { ?s schema:actor ?a . }
                OPTIONAL { ?s schema:countryOfOrigin ?c . }
                OPTIONAL {
                    ?s schema:aggregateRating ?r .
                    ?r schema:ratingValue ?rating .
                    OPTIONAL { ?r schema:ratingCount ?votes . }
                }
                } GROUP BY ?s ?name ?startDate ?endDate ?numberOfSeasons ?award ?image ?description ?rating ?votes
                ORDER BY DESC(?rating)
            ');

            return array_map(fn ($r) => [
                'id'               => basename(rtrim($r['s'], '/')),
                'name'             => $r['name'],
                'aggregateRating'  => isset($r['rating']) ? (float) $r['rating'] : 0,
                'interactionCount' => isset($r['votes']) ? (int) $r['votes'] : 0,
                'startDate'        => $r['startDate'] ?? null,
                'endDate'          => $r['endDate'] ?? null,
                'numberOfSeasons'  => $r['numberOfSeasons'] ?? null,
                'award'            => $r['award'] ?? '',
                'countryOfOrigin'  => implode(', ', self::labels($r['countries'] ?? null)),
                'image'            => $r['image'] ?? '',
                'description'      => $r['description'] ?? '',
                'genre'            => implode(', ', self::labels($r['genres'] ?? null)),
                'actor'            => implode(', ', self::labels($r['actors'] ?? null)),
            ], $rows);
        });

        if ($withDescription) return $all;

        return array_map(function ($s) { unset($s['description']); return $s; }, $all);
    }

    public function home()
    { 
        return view('home',      ['shows' => $this->allShows()]); 
    }

    public function search()   
    { 
        return view('search',    ['shows' => $this->allShows()]); 
    }

    public function genre()    
    { 
        return view('genre',     ['shows' => $this->allShows()]); 
    }

    public function topRated() 
    { 
        return view('top-rated', ['shows' => $this->allShows(true)]); 
    }

    public function category(string $slug)
    {
        abort_unless(in_array($slug, ['top-picks', 'popular', 'long-running', 'awards', 'asian'], true), 404);
        return view('category', ['shows' => $this->allShows(), 'slug' => $slug]);
    }

    public function detail(string $id)
    {
        abort_unless(preg_match('/^tt\d+$/', $id), 404);
        $uri = "https://www.imdb.com/title/{$id}/";

        $rows = $this->sparql->query("
            PREFIX schema: <https://schema.org/>
            SELECT ?p ?o ?rv ?rc WHERE {
              <$uri> ?p ?o .
              OPTIONAL { ?o schema:ratingValue ?rv . }
              OPTIONAL { ?o schema:ratingCount ?rc . }
            }
        ");
        abort_if(empty($rows), 404);

        $multi = [
            'genre' => 'genre', 'actor' => 'actor', 'author' => 'author',
            'countryOfOrigin' => 'countryOfOrigin', 'inLanguage' => 'inLanguage',
        ];
        $single = [
            'name', 'description', 'image', 'award', 'contentRating',
            'startDate', 'endDate', 'numberOfSeasons',
        ];

        $show = ['id' => $id, 'uri' => $uri];
        $lists = [];

        foreach ($rows as $r) {
            $key = preg_replace('/^.*[\/#]/', '', $r['p']);
            if (isset($multi[$key])) {
                $lists[$multi[$key]][] = self::label($r['o']);
            } elseif (in_array($key, $single, true)) {
                $show[$key] = $r['o'];
            } elseif ($key === 'duration') {
                $show['duration'] = self::minutes($r['o']);
            } elseif ($key === 'aggregateRating') {
                $show['aggregateRating']  = isset($r['rv']) ? (float) $r['rv'] : 0;
                $show['interactionCount'] = isset($r['rc']) ? (int) $r['rc'] : 0;
            }
        }
        foreach ($lists as $k => $v) {
            $show[$k] = implode(', ', array_unique($v));
        }

        return view('detail', compact('show'));
    }
}