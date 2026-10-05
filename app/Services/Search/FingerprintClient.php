<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calls choosetounsi-ai-service (services.ai.url, AI_SERVICE_URL in .env) through Http::ai():
 *
 *   GET  /health        is it up, which model (camera button, admin, rebuild)
 *   POST /embed/query   a customer's photo → averaged vector + dominant color   (≈3 s timeout)
 *   POST /embed/image   catalog photos     → vector + dominant color each       (queued jobs, long timeout)
 *
 * A failed or slow query marks the service down for DOWN_SECONDS, so while it is stopped every
 * photo search answers "unavailable" at once instead of waiting for the timeout. Text search
 * never calls it.
 */
class FingerprintClient
{
    const DOWN_FLAG = 'image_search:service_down';
    const DOWN_SECONDS = 60;
    const HEALTH_KEY = 'image_search:health';

    /**
     * @return array{vector: float[], color: ?string, model: string}
     * @throws SearchUnavailable service down, slow or unhappy with the photo
     */
    public function query(string $bytes): array
    {
        if (Cache::has(self::DOWN_FLAG)) {
            throw new SearchUnavailable('Photo service marked down');
        }
        $key = 'image_search:qvec:' . sha1($bytes);
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $res = Http::ai()->timeout((float) config('search.image.timeout', 3))
                ->withOptions(['connect_timeout' => 1])
                ->attach('file', $bytes, 'query.jpg')
                ->post($this->url('/embed/query'));
        } catch (Throwable $e) {
            $this->markDown($e->getMessage());
            throw new SearchUnavailable('Photo service unreachable: ' . $e->getMessage(), 0, $e);
        }
        if ($res->status() === 422 || $res->status() === 413) {
            throw new SearchUnavailable('Photo rejected: HTTP ' . $res->status(), $res->status());
        }
        if (!$res->successful() || count($res->json('vector') ?? []) !== Fingerprint::DIMS) {
            $this->markDown('HTTP ' . $res->status());
            throw new SearchUnavailable('Photo service: HTTP ' . $res->status(), $res->status());
        }

        $out = ['vector' => $res->json('vector'), 'color' => $res->json('color'), 'model' => (string) $res->json('model')];
        Cache::put($key, $out, now()->addHour());
        return $out;
    }

    /**
     * Catalog photos (indexing). Longer timeouts: this runs in queued jobs and the rebuild command.
     *
     * @param  array<int|string, string> $images key => raw bytes
     * @return array{model: ?string, items: array<int|string, array{vector: float[], color: ?string}|null>}
     *         null item = the service couldn't read that file
     * @throws SearchUnavailable when the service is unreachable (the job retries later)
     */
    public function describe(array $images): array
    {
        $out = ['model' => null, 'items' => []];
        foreach (array_chunk($images, 8, true) as $chunk) {
            $req = Http::ai()->timeout(60)->withOptions(['connect_timeout' => 2]);
            foreach ($chunk as $k => $bytes) {
                $req = $req->attach('files', $bytes, "image-$k.jpg");
            }
            try {
                $res = $req->post($this->url('/embed/image'));
            } catch (Throwable $e) {
                throw new SearchUnavailable('Photo service unreachable: ' . $e->getMessage(), 0, $e);
            }
            if ($res->status() === 422) {          // every image in the chunk unreadable
                foreach (array_keys($chunk) as $k) {
                    $out['items'][$k] = null;
                }
                continue;
            }
            if (!$res->successful()) {
                throw new SearchUnavailable('Photo service: HTTP ' . $res->status(), $res->status());
            }
            $out['model'] = (string) $res->json('model');
            foreach (array_keys($chunk) as $i => $k) {
                $vector = $res->json("vectors.$i");
                $out['items'][$k] = $vector ? ['vector' => $vector, 'color' => $res->json("colors.$i")] : null;
            }
        }
        return $out;
    }

    /** /health of the service, remembered 30 s (null = down). */
    public function health(): ?array
    {
        if (Cache::has(self::DOWN_FLAG)) {
            return null;
        }
        $health = Cache::remember(self::HEALTH_KEY, 30, function () {
            try {
                $res = Http::ai()->timeout(1.5)->withOptions(['connect_timeout' => 1])->get($this->url('/health'));
                return $res->successful() && $res->json('status') === 'ok' ? $res->json() : [];
            } catch (Throwable) {
                return [];
            }
        });
        return $health ?: null;
    }

    /** The model the service runs now (null when it is down). */
    public function model(): ?string
    {
        return $this->health()['image_model'] ?? null;
    }

    private function markDown(string $why): void
    {
        Cache::put(self::DOWN_FLAG, true, self::DOWN_SECONDS);
        Cache::forget(self::HEALTH_KEY);
        Log::warning("[ImageSearch] Photo service unavailable, photo search off for " . self::DOWN_SECONDS . "s: $why");
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.ai.url', 'http://127.0.0.1:8001'), '/') . $path;
    }
}
