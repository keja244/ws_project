<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ReviewController extends Controller
{
    public function show(int $tmdbId)
    {
        $data = Cache::remember("tmdb_reviews_{$tmdbId}", now()->addHours(12), function () use ($tmdbId) {
            $res = Http::timeout(8)
                ->withToken(config('services.tmdb.token'))
                ->acceptJson()
                ->get("https://api.themoviedb.org/3/tv/{$tmdbId}/reviews", [
                    'language' => 'en-US',
                    'page'     => 1,
                ]);

            return $res->successful() ? $res->json() : null;
        });

        if ($data === null) {
            return response()->json(['error' => 'TMDB unavailable'], 502);
        }

        $reviews = collect($data['results'] ?? [])->map(fn ($r) => [
            'author'  => $r['author_details']['name'] ?: $r['author'],
            'user'    => $r['author_details']['username'] ?? $r['author'],
            'avatar'  => $r['author_details']['avatar_path'] ?? null,
            'rating'  => $r['author_details']['rating'] ?? null,
            'content' => $r['content'] ?? '',
            'date'    => $r['created_at'] ?? null,
            'url'     => $r['url'] ?? null,
        ])->values();

        return response()->json([
            'total'   => $data['total_results'] ?? $reviews->count(),
            'reviews' => $reviews,
        ]);
    }
}