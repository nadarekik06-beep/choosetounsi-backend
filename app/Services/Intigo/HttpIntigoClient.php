<?php

namespace App\Services\Intigo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Live Intigo V3 client (config('intigo.mode') = live). Real parcels, real drivers.
 * JSON over HTTPS, header X-API-Key on every call, identification headers on
 * parcel creation. Every request and answer is logged (key redacted).
 */
class HttpIntigoClient implements IntigoClient
{
    public function __construct(private IntigoLogger $logger) {}

    public function mode(): string
    {
        return 'live';
    }

    public function createParcel(array $payload, ?int $sellerOrderId = null): array
    {
        $body = $this->request('POST', '/parcels/by-name', $payload, $sellerOrderId, [
            'X-Intigo-Platform'         => config('intigo.platform'),
            'X-Intigo-Platform-Version' => config('intigo.platform_version'),
            'X-Intigo-Shop'             => config('intigo.shop'),
        ]);

        $nid = is_array($body) ? ($body['nid'] ?? $body['data']['nid'] ?? null) : null;
        if (!is_string($nid) && !is_int($nid)) {
            // 2xx without a nid: the parcel may exist; treat as ambiguous (look the cid up)
            throw new IntigoException('Intigo answered without a parcel id (nid)', 201, true, 'server', $body);
        }

        return [
            'nid'               => (string) $nid,
            'district_name'     => $body['district_name'] ?? null,
            'district_fallback' => isset($body['district_fallback']) ? (bool) $body['district_fallback'] : null,
            'raw'               => $body,
        ];
    }

    public function findByCid(string $cid, ?int $sellerOrderId = null): array
    {
        $found = [];
        // The list may be paginated: read a few pages at most (our parcel is recent)
        for ($page = 1; $page <= 5; $page++) {
            $body  = $this->request('GET', '/parcels/', null, $sellerOrderId, [], ['cid' => $cid, 'page' => $page]);
            $items = Shapes::list($body, ['parcels', 'data', 'results', 'items']);
            foreach ($items as $item) {
                $p = Shapes::parcel($item);
                if ($p && $p['cid'] === $cid) {
                    $found[$p['nid']] = $p;
                }
            }
            if ($found || count($items) === 0 || !Shapes::hasMore($body, $page)) {
                break;
            }
        }
        return array_values($found);
    }

    public function getParcel(string $nid, ?int $sellerOrderId = null): array
    {
        return (array) $this->request('GET', '/parcels/' . rawurlencode($nid), null, $sellerOrderId);
    }

    public function history(string $nid, ?int $sellerOrderId = null): array
    {
        return (array) $this->request('GET', '/parcels/' . rawurlencode($nid) . '/history', null, $sellerOrderId);
    }

    public function statuses(array $nids): array
    {
        $nids = array_values(array_unique(array_filter(array_map('strval', $nids))));
        if (!$nids) {
            return [];
        }
        $body = $this->request('POST', '/parcels/status', ['nids' => $nids], null);

        $out = [];
        foreach (Shapes::list($body, ['parcels', 'data', 'results', 'statuses', 'items']) as $item) {
            $p = Shapes::parcel($item);
            if ($p && $p['status'] !== null) {
                $out[$p['nid']] = ['status' => $p['status'], 'status_label' => $p['status_label']];
            }
        }
        // Some APIs answer {nid: {status…}}
        if (!$out && is_array($body)) {
            foreach ($nids as $nid) {
                if (isset($body[$nid]) && is_array($body[$nid]) && ($p = Shapes::parcel($body[$nid] + ['nid' => $nid])) && $p['status'] !== null) {
                    $out[$nid] = ['status' => $p['status'], 'status_label' => $p['status_label']];
                }
            }
        }
        return $out;
    }

    public function cancel(string $nid, ?int $sellerOrderId = null): array
    {
        return (array) $this->request('POST', '/parcels/' . rawurlencode($nid) . '/cancel', [], $sellerOrderId);
    }

    public function pickupAddresses(): array
    {
        $body = $this->request('GET', '/pickup-addresses/', null, null);
        return Shapes::pickupAddresses($body);
    }

    public function createPickupAddress(array $body): array
    {
        return $this->pickupAnswer($this->request('POST', '/pickup-addresses/', $body, null));
    }

    public function updatePickupAddress(int $index, array $body): array
    {
        return $this->pickupAnswer($this->request('PATCH', '/pickup-addresses/' . $index, $body, null), $index);
    }

    private function pickupAnswer(mixed $body, ?int $index = null): array
    {
        $item = Shapes::pickupAddress($body);
        if ($item === null || ($item['index'] === null && $index === null)) {
            throw new IntigoException('Intigo answered without a pickup address index', 200, false, 'server', $body);
        }
        $item['index'] ??= $index;
        return $item;
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /** @throws IntigoException */
    private function request(string $method, string $path, ?array $body, ?int $sellerOrderId, array $headers = [], array $query = []): mixed
    {
        $key = config('intigo.api_key');
        if (!is_string($key) || trim($key) === '') {
            $e = new IntigoException('INTIGO_API_KEY is not set', null, false, 'auth');
            $this->logger->log('live', $method, $path, $body, null, null, 0, $sellerOrderId, $e->getMessage());
            throw $e;
        }

        $url     = config('intigo.base_url') . $path . ($query ? '?' . http_build_query($query) : '');
        $started = microtime(true);
        try {
            $response = $this->http($headers + ['X-API-Key' => $key])->send($method, $url, $body !== null ? ['json' => $body] : []);
        } catch (ConnectionException $e) {
            $ex = IntigoException::connection($e->getMessage());
            $this->logger->log('live', $method, $path . ($query ? '?' . http_build_query($query) : ''), $body, null, null, $this->ms($started), $sellerOrderId, $ex->getMessage());
            throw $ex;
        } catch (\Throwable $e) {
            // Guzzle transfer errors (timeouts…) surface as other exception types
            $ex = IntigoException::connection($e->getMessage());
            $this->logger->log('live', $method, $path, $body, null, null, $this->ms($started), $sellerOrderId, $ex->getMessage());
            throw $ex;
        }

        $decoded = $response->json() ?? $response->body();
        $status  = $response->status();
        $error   = $response->successful() ? null : IntigoException::fromResponse($status, $decoded)->getMessage();
        $this->logger->log('live', $method, $path . ($query ? '?' . http_build_query($query) : ''), $body, $status, $decoded, $this->ms($started), $sellerOrderId, $error);

        if (!$response->successful()) {
            throw IntigoException::fromResponse($status, $decoded);
        }
        return $decoded;
    }

    private function http(array $headers): PendingRequest
    {
        return Http::withHeaders($headers + ['Accept' => 'application/json'])
            ->timeout(config('intigo.http.timeout', 20))
            ->withOptions(['connect_timeout' => config('intigo.http.connect_timeout', 8)])
            ->asJson();
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
