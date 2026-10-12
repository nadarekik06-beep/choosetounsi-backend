<?php

namespace Tests\Feature\Intigo;

use App\Services\Intigo\IntigoClient;
use App\Services\Intigo\IntigoException;

/**
 * Test double: answers come from scripts, every call is counted. No network.
 *
 *   $client->onCreate[] = fn (array $payload) => [...answer] | throw IntigoException
 *   $client->atIntigo['CT-SO-12'] = [['nid' => 'X1', 'cid' => 'CT-SO-12', 'status' => 1000, 'status_label' => null]]
 *     (what GET /parcels/?cid= finds)
 */
class ScriptedIntigoClient implements IntigoClient
{
    /** @var array<int, \Closure> consumed in order; when empty, create succeeds with nid "SCR-{n}" */
    public array $onCreate = [];
    public array $atIntigo = [];
    public array $statuses = [];
    public ?\Closure $onCancel = null;
    public array $calls = ['create' => [], 'findByCid' => [], 'cancel' => [], 'statuses' => []];

    public function mode(): string { return 'fake'; }

    public function createParcel(array $payload, ?int $sellerOrderId = null): array
    {
        $this->calls['create'][] = $payload;
        $script = array_shift($this->onCreate);
        $answer = $script ? $script($payload, $this) : ['nid' => 'SCR-' . count($this->calls['create'])];
        // A created parcel can be found again by its cid
        $this->atIntigo[$payload['cid']][] = ['nid' => $answer['nid'], 'cid' => $payload['cid'], 'status' => 1000, 'status_label' => null];
        return $answer + ['district_name' => $payload['district_name'], 'district_fallback' => false, 'raw' => $answer];
    }

    public function findByCid(string $cid, ?int $sellerOrderId = null): array
    {
        $this->calls['findByCid'][] = $cid;
        return $this->atIntigo[$cid] ?? [];
    }

    public function getParcel(string $nid, ?int $sellerOrderId = null): array { return ['nid' => $nid]; }

    public function history(string $nid, ?int $sellerOrderId = null): array { return []; }

    public function statuses(array $nids): array
    {
        $this->calls['statuses'][] = $nids;
        return array_intersect_key($this->statuses, array_flip($nids));
    }

    public function cancel(string $nid, ?int $sellerOrderId = null): array
    {
        $this->calls['cancel'][] = $nid;
        return $this->onCancel ? ($this->onCancel)($nid) : ['success' => true];
    }

    public function pickupAddresses(): array
    {
        return [['index' => 0, 'label' => 'Main', 'raw' => []], ['index' => 3, 'label' => 'Shop X', 'raw' => []]];
    }

    /** "Created at Intigo, but the answer was lost" */
    public static function timeoutAfterCreating(string $nid = 'LOST-1'): \Closure
    {
        return function (array $payload, self $client) use ($nid) {
            $client->atIntigo[$payload['cid']][] = ['nid' => $nid, 'cid' => $payload['cid'], 'status' => 1000, 'status_label' => null];
            throw IntigoException::connection('cURL error 28: Operation timed out');
        };
    }

    /** No answer, and nothing was created */
    public static function timeoutWithoutCreating(): \Closure
    {
        return fn () => throw IntigoException::connection('cURL error 7: Failed to connect');
    }

    public static function fails(int $status, array $body): \Closure
    {
        return fn () => throw IntigoException::fromResponse($status, $body);
    }
}
