<?php

namespace App\Services\Intigo;

/**
 * Every call to Intigo's parcel API goes through this interface, bound in
 * AppServiceProvider: HttpIntigoClient when config('intigo.mode') = live,
 * FakeIntigoClient otherwise (no network, no real parcel). Each call is
 * written to intigo_logs. $sellerOrderId only labels the log row.
 *
 * Failures throw IntigoException (retryable or not, with a readable message).
 */
interface IntigoClient
{
    public function mode(): string;

    /**
     * POST /parcels/by-name. Creates a REAL parcel in live mode (a driver comes).
     *
     * @return array{nid:string, district_name:?string, district_fallback:?bool, raw:array}
     */
    public function createParcel(array $payload, ?int $sellerOrderId = null): array;

    /**
     * GET /parcels/ filtered on our correlation id: the parcels Intigo knows
     * under that cid (normally one), newest first.
     *
     * @return array<int, array{nid:string, cid:?string, status:?int, status_label:?string}>
     */
    public function findByCid(string $cid, ?int $sellerOrderId = null): array;

    /** GET /parcels/{nid} */
    public function getParcel(string $nid, ?int $sellerOrderId = null): array;

    /** GET /parcels/{nid}/history */
    public function history(string $nid, ?int $sellerOrderId = null): array;

    /**
     * POST /parcels/status: current status of several parcels.
     *
     * @param string[] $nids
     * @return array<string, array{status:int, status_label:?string}> keyed by nid (unknown nids absent)
     */
    public function statuses(array $nids): array;

    /** POST /parcels/{nid}/cancel (409 once past the pickup phase). */
    public function cancel(string $nid, ?int $sellerOrderId = null): array;

    /**
     * GET /pickup-addresses/: the account's pickup addresses.
     *
     * @return array<int, array{index:int, label:string, raw:array}>
     */
    public function pickupAddresses(): array;
}
