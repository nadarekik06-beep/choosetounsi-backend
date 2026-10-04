<?php

namespace App\Services\Search;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin Meilisearch REST client on Laravel's HTTP client (so tests can Http::fake() it).
 * Scout keeps using meilisearch-php for syncing Product documents; searching, settings and
 * the image index go through here. Every failure surfaces as SearchUnavailable.
 *
 * When Meilisearch cannot be reached, calls fail fast for DOWN_SECONDS instead of each one
 * waiting for the connect timeout (keeps requests quick while it is stopped, e.g. in local dev).
 */
class MeiliClient
{
    private const DOWN_KEY = 'search:meilisearch_down';
    private const DOWN_SECONDS = 30;

    private function http(?float $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('search.meilisearch.host'), '/'))
            ->withToken((string) config('search.meilisearch.key'))
            ->acceptJson()
            ->timeout($timeout ?? (float) config('search.meilisearch.timeout', 2))
            ->withOptions(['connect_timeout' => 1]);
    }

    public function index(string $key): string
    {
        return (string) config("search.indexes.$key", $key);
    }

    public function search(string $index, array $params, ?float $timeout = null): array
    {
        return $this->send('post', "indexes/{$this->index($index)}/search", $params, $timeout);
    }

    /**
     * Several searches in one HTTP round trip.
     * @param  array<int, array{0: string, 1: array}> $queries [index key, search params]
     * @return array<int, array> one search response per query, same order
     */
    public function multiSearch(array $queries, ?float $timeout = null): array
    {
        $body = ['queries' => array_map(fn ($q) => ['indexUid' => $this->index($q[0])] + $q[1], $queries)];
        return $this->send('post', 'multi-search', $body, $timeout)['results'] ?? [];
    }

    public function similar(string $index, array $params): array
    {
        return $this->send('post', "indexes/{$this->index($index)}/similar", $params);
    }

    /** @return array task */
    public function addDocuments(string $index, array $documents): array
    {
        return $this->send('post', "indexes/{$this->index($index)}/documents?primaryKey=id", $documents, 30);
    }

    public function deleteByFilter(string $index, string $filter): array
    {
        return $this->send('post', "indexes/{$this->index($index)}/documents/delete", ['filter' => $filter], 30);
    }

    public function deleteDocuments(string $index, array $ids): array
    {
        return $this->send('post', "indexes/{$this->index($index)}/documents/delete-batch", array_values($ids), 30);
    }

    public function createIndex(string $index): array
    {
        return $this->send('post', 'indexes', ['uid' => $this->index($index), 'primaryKey' => 'id'], 30);
    }

    public function indexExists(string $index): bool
    {
        try {
            $this->send('get', "indexes/{$this->index($index)}");
            return true;
        } catch (SearchUnavailable $e) {
            if ($e->status === 404) {
                return false;
            }
            throw $e;
        }
    }

    public function updateSettings(string $index, array $settings): array
    {
        return $this->send('patch', "indexes/{$this->index($index)}/settings", $settings, 30);
    }

    /** @return int[] every document id in the index */
    public function documentIds(string $index): array
    {
        return array_keys($this->allDocuments($index, 'id'));
    }

    /** @return array<int, int> image document id => product_id */
    public function imageProductIds(): array
    {
        return array_map(fn ($doc) => (int) $doc['product_id'], $this->allDocuments('images', 'id,product_id'));
    }

    /** @return array<int, array> id => document (only the requested fields), paged through */
    private function allDocuments(string $index, string $fields): array
    {
        $docs = [];
        for ($offset = 0; ; $offset += 1000) {
            $page = $this->send('get', "indexes/{$this->index($index)}/documents?fields=$fields&limit=1000&offset=$offset", null, 30);
            foreach ($page['results'] ?? [] as $doc) {
                $docs[(int) $doc['id']] = $doc;
            }
            if (count($page['results'] ?? []) < 1000) {
                return $docs;
            }
        }
    }

    public function stats(string $index): array
    {
        return $this->send('get', "indexes/{$this->index($index)}/stats");
    }

    /** Wait for an asynchronous task (settings/documents) and return it; throws if it failed. */
    public function wait(array $task, int $timeoutSeconds = 300): array
    {
        $uid = $task['taskUid'] ?? $task['uid'] ?? null;
        if ($uid === null) {
            return $task;
        }
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $state = $this->send('get', "tasks/$uid");
            if (in_array($state['status'] ?? '', ['succeeded', 'failed', 'canceled'], true)) {
                if ($state['status'] !== 'succeeded') {
                    throw new SearchUnavailable('Meilisearch task ' . $uid . ' ' . $state['status'] . ': ' . json_encode($state['error'] ?? null));
                }
                return $state;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new SearchUnavailable("Meilisearch task $uid still running after {$timeoutSeconds}s");
    }

    public function healthy(): bool
    {
        try {
            return ($this->send('get', 'health', null, 1, probe: true)['status'] ?? null) === 'available';
        } catch (SearchUnavailable) {
            return false;
        }
    }

    private function send(string $method, string $path, ?array $body = null, ?float $timeout = null, bool $probe = false): array
    {
        if (!$probe && Cache::get(self::DOWN_KEY)) {
            throw new SearchUnavailable("Meilisearch unreachable (not retried for " . self::DOWN_SECONDS . "s) for $path");
        }
        try {
            /** @var Response $res */
            $res = $body === null ? $this->http($timeout)->{$method}($path) : $this->http($timeout)->{$method}($path, $body);
        } catch (ConnectionException $e) {
            Cache::put(self::DOWN_KEY, true, self::DOWN_SECONDS);
            throw new SearchUnavailable('Meilisearch unreachable: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new SearchUnavailable('Meilisearch unreachable: ' . $e->getMessage(), 0, $e);
        }
        if ($probe) {
            Cache::forget(self::DOWN_KEY);
        }
        if (!$res->successful()) {
            throw new SearchUnavailable("Meilisearch $method $path → HTTP {$res->status()}: " . mb_substr($res->body(), 0, 300), $res->status());
        }
        return $res->json() ?? [];
    }
}
