<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SparqlService
{
    public function query(string $sparql): array
    {
        $response = Http::timeout((int) config('services.fuseki.timeout'))
            ->withHeaders([
                'Accept' => 'application/sparql-results+json',
            ])
            ->asForm()
            ->post(config('services.fuseki.endpoint'), [
                'query' => $sparql,
            ])
            ->throw();

        $bindings =$response->json('results.bindings');

        if (!is_array($bindings)) {
            throw new \RuntimeException('Format respons SPARQL tidak valid.');
        }

        return $this->flattenBindings($bindings);
    }

    public function queryWikidata(string $sparql): array
    {
        try {
            $response = Http::timeout((int) config('services.wikidata.timeout'))
                ->withHeaders([
                    'Accept'     => 'application/sparql-results+json',
                    'User-Agent' => (string) config('services.wikidata.user_agent'),
                ])
                ->get(config('services.wikidata.endpoint'), [
                    'query' => $sparql,
                ]);

            if ($response->failed()) {
                return [];
            }

            $bindings =$response->json('results.bindings');

            return is_array($bindings) ? $this->flattenBindings($bindings) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function flattenBindings(array $bindings): array
    {
        return collect($bindings)
            ->map(fn ($row) => collect($row)
                ->map(fn ($value) =>$value['value'])
                ->all()
            )
            ->all();
    }

    public static function esc(string $value): string
    {
        return addcslashes($value, "\\\"'\n\r\t");
    }
}