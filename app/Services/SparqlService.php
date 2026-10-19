<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SparqlService
{
    public function query(string $sparql): array
    {
        $response = Http::timeout(15)
            ->withHeaders(['Accept' => 'application/sparql-results+json'])
            ->asForm()
            ->post(config('services.fuseki.endpoint'), ['query' => $sparql])
            ->throw();

        return collect($response->json('results.bindings'))
            ->map(fn ($row) => collect($row)->map(fn ($v) => $v['value'])->all())
            ->all();
    }

    public static function esc(string $value): string
    {
        return addcslashes($value, "\\\"'\n\r\t");
    }
}