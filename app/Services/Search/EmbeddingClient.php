<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Text vectors for the semantic fallback: choosetounsi-ai-service POST /embed/text through Http::ai().
 * (Photos: FingerprintClient.)
 *
 * Query-time calls are short and skip the service for a minute after a failure, so a
 * stopped service costs one slow request, not one per search. Indexing calls get longer
 * timeouts and throw.
 */
class EmbeddingClient
{
    const DOWN_FLAG = 'search:embedder_down';

    public function semanticEnabled(): bool
    {
        return (bool) config('search.semantic.enabled');
    }

    /** Vector for a search query, or null (semantic off, service down/slow). Cached a day per query. */
    public function queryVector(string $normalizedQuery): ?array
    {
        if (!$this->semanticEnabled() || $normalizedQuery === '' || Cache::has(self::DOWN_FLAG)) {
            return null;
        }

        $key = 'search:qvec:' . sha1($normalizedQuery);
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $vector = $this->texts([$normalizedQuery], (float) config('search.semantic.timeout', 1.5))[0];
        } catch (SearchUnavailable $e) {
            $this->markDown($e);
            return null;
        }
        Cache::put($key, $vector, now()->addDay());
        return $vector;
    }

    /**
     * Vectors for product documents (indexing). Cached by content, so an unchanged product
     * is never re-embedded by a reindex.
     *
     * @param  array<int|string, string> $texts
     * @return array<int|string, array>|null  same keys; null when semantic search is off
     * @throws SearchUnavailable
     */
    public function documentVectors(array $texts): ?array
    {
        if (!$this->semanticEnabled() || !$texts) {
            return $this->semanticEnabled() ? [] : null;
        }

        $out = $missing = [];
        foreach ($texts as $k => $text) {
            $cached = Cache::get('search:dvec:' . sha1($text));
            is_array($cached) ? $out[$k] = $cached : $missing[$k] = $text;
        }
        foreach (array_chunk($missing, 32, true) as $chunk) {
            $vectors = $this->texts(array_values($chunk), 30);
            foreach (array_keys($chunk) as $i => $k) {
                $out[$k] = $vectors[$i];
                Cache::put('search:dvec:' . sha1($chunk[$k]), $vectors[$i], now()->addDays(60));
            }
        }
        return $out;
    }


    /** @throws SearchUnavailable */
    private function texts(array $texts, float $timeout): array
    {
        try {
            $res = Http::ai()->timeout($timeout)->withOptions(['connect_timeout' => 1])
                ->post($this->url('/embed/text'), ['texts' => array_values($texts)]);
        } catch (Throwable $e) {
            throw new SearchUnavailable('Embedding service unreachable: ' . $e->getMessage(), 0, $e);
        }
        if (!$res->successful() || count($res->json('vectors') ?? []) !== count($texts)) {
            throw new SearchUnavailable('Embedding service: HTTP ' . $res->status(), $res->status());
        }
        return $res->json('vectors');
    }

    private function markDown(Throwable $e): void
    {
        Cache::put(self::DOWN_FLAG, true, now()->addMinutes(5));
        Log::info('[Search] Embedding service unavailable, keyword search only for 5 minutes: ' . $e->getMessage());
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.ai.url', 'http://127.0.0.1:8001'), '/') . $path;
    }
}
