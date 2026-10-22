<?php

namespace App\Services\Intigo;

use App\Models\SellerOrder;
use App\Models\SellerPickupAddress;
use App\Support\Wilayas;
use Illuminate\Support\Facades\Cache;

/**
 * Last check before a parcel is created (live mode): the pickup index we are
 * about to send exists in Intigo's own list (GET /pickup-addresses/) and is
 * in the same governorate as the seller's pickup address in our DB.
 *
 * The list is cached config('intigo.pickup_check_cache_seconds'); an index
 * missing from the cached copy is looked up once more without the cache (a
 * pickup created a minute ago). Only items carrying an explicit index count:
 * never a position in the list.
 *
 * Throws IntigoException when the list can't be read (the sender treats a
 * retryable one like any other Intigo outage).
 */
class PickupVerifier
{
    private const CACHE_KEY = 'intigo.pickup_addresses.v1';

    public function __construct(private IntigoClient $client) {}

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** null = OK, else why the parcel must not be sent. */
    public function problem(SellerOrder $so, int $index): ?string
    {
        $entry = $this->find($index, true) ?? $this->find($index, false);
        if ($entry === null) {
            return "pickup index {$index} does not exist at Intigo (GET /pickup-addresses/): create the seller's pickup in Intigo first";
        }
        if ($so->isPlatformParcel()) {
            return null;
        }

        // Same governorate as the seller's pickup address in our DB
        $record = SellerPickupAddress::with('zone')->where('user_id', $so->seller_id)->first();
        $ours   = self::wilaya($record?->zone?->wilaya ?: $record?->zone?->gouvernorat ?: $record?->gouvernorat);
        $theirs = self::wilaya($entry['city_name']);
        if ($ours !== null && $theirs !== null && $ours !== $theirs) {
            return "pickup index {$index} at Intigo is in {$entry['city_name']}, but this seller's pickup address is in "
                . ($record?->zone?->gouvernorat ?? $ours) . ': the index belongs to another address';
        }
        return null;
    }

    /** @return array{index:int, city_name:?string, district_name:?string, label:string}|null */
    public function find(int $index, bool $cached = true): ?array
    {
        foreach ($this->list($cached) as $entry) {
            if ($entry['index'] === $index) {
                return $entry;
            }
        }
        return null;
    }

    /** @return array<int, array{index:int, city_name:?string, district_name:?string, label:string}> */
    public function list(bool $cached = true): array
    {
        if ($cached && is_array($hit = Cache::get(self::CACHE_KEY))) {
            return $hit;
        }
        $out = [];
        foreach ($this->client->pickupAddresses() as $item) {
            $raw = is_array($item['raw'] ?? null) ? $item['raw'] : [];
            $explicit = $raw['index'] ?? $raw['pickup_index'] ?? null;
            if (!is_numeric($explicit)) {
                continue;   // no index in Intigo's answer: never guess one from the list position
            }
            $out[] = [
                'index'         => (int) $explicit,
                'city_name'     => is_string($raw['city_name'] ?? null) ? $raw['city_name'] : null,
                'district_name' => is_string($raw['district_name'] ?? null) ? $raw['district_name'] : null,
                'label'         => (string) ($item['label'] ?? ''),
            ];
        }
        Cache::put(self::CACHE_KEY, $out, (int) config('intigo.pickup_check_cache_seconds', 300));
        return $out;
    }

    private static function wilaya(?string $name): ?string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') return null;
        return IntigoRegions::wilayaFor($name) ?? Wilayas::normalize($name);
    }
}
