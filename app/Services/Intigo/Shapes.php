<?php

namespace App\Services\Intigo;

/**
 * Tolerant readers for Intigo's JSON answers: lists may come bare or under a
 * key ("parcels", "data"…), a status as a code or as {code, label}.
 */
class Shapes
{
    /** The first list found under $keys (or the body itself when it is a list). */
    public static function list(mixed $body, array $keys): array
    {
        if (!is_array($body)) {
            return [];
        }
        if (array_is_list($body)) {
            return $body;
        }
        foreach ($keys as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $inner = $body[$key];
                if (array_is_list($inner)) {
                    return $inner;
                }
                // {data: {parcels: […]}} / {data: {results: […]}}
                foreach ($keys as $k2) {
                    if (isset($inner[$k2]) && is_array($inner[$k2]) && array_is_list($inner[$k2])) {
                        return $inner[$k2];
                    }
                }
            }
        }
        return [];
    }

    /** @return array{nid:string, cid:?string, status:?int, status_label:?string}|null */
    public static function parcel(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        $nid = $item['nid'] ?? $item['parcel_nid'] ?? $item['id'] ?? null;
        if ($nid === null || $nid === '') {
            return null;
        }
        $status = $item['status'] ?? $item['status_code'] ?? $item['current_status'] ?? $item['new_status'] ?? null;
        $label  = $item['status_label'] ?? $item['status_name'] ?? $item['label'] ?? null;
        if (is_array($status)) {
            $label  = $status['label'] ?? $status['name'] ?? $label;
            $status = $status['code'] ?? $status['id'] ?? $status['status'] ?? null;
        }
        return [
            'nid'          => (string) $nid,
            'cid'          => isset($item['cid']) ? (string) $item['cid'] : null,
            'status'       => is_numeric($status) ? (int) $status : null,
            'status_label' => is_string($label) ? mb_substr($label, 0, 120) : null,
        ];
    }

    public static function hasMore(mixed $body, int $page): bool
    {
        if (!is_array($body)) {
            return false;
        }
        if (!empty($body['next']) || !empty($body['has_more']) || !empty($body['next_page_url'])) {
            return true;
        }
        // Intigo v3: {"parcels": [...], "pagination": {"total", "page", "page_size", "total_pages"}}
        $last = $body['pagination']['total_pages'] ?? $body['total_pages'] ?? $body['last_page'] ?? $body['pages'] ?? $body['meta']['last_page'] ?? null;
        return is_numeric($last) && $page < (int) $last;
    }

    /** @return array<int, array{index:int, label:string, raw:array}> */
    /**
     * One pickup address answer ({pickup_address: {...}}).
     *
     * @return array{index:?int, label:string, raw:array}|null
     */
    public static function pickupAddress(mixed $body): ?array
    {
        $item = is_array($body) ? ($body['pickup_address'] ?? $body['data'] ?? $body) : null;
        if (!is_array($item)) {
            return null;
        }
        $index = $item['index'] ?? $item['pickup_index'] ?? null;
        $parts = array_filter([
            $item['street_address'] ?? $item['address'] ?? null,
            $item['district_name'] ?? null,
            $item['city_name'] ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '');
        return [
            'index' => is_numeric($index) ? (int) $index : null,
            'label' => $parts ? implode(' — ', array_map('trim', $parts)) : 'Pickup address #' . $index,
            'raw'   => $item,
        ];
    }

    public static function pickupAddresses(mixed $body): array
    {
        $out = [];
        foreach (self::list($body, ['pickup_addresses', 'addresses', 'data', 'results', 'items']) as $i => $item) {
            if (!is_array($item)) continue;
            $index = $item['index'] ?? $item['pickup_index'] ?? $item['idx'] ?? $i;
            $parts = array_filter([
                $item['name'] ?? $item['label'] ?? null,
                $item['street_address'] ?? $item['address'] ?? $item['full_address'] ?? $item['adresse'] ?? null,
                $item['district_name'] ?? $item['district'] ?? null,
                $item['city_name'] ?? $item['city'] ?? null,
                $item['phone'] ?? null,
            ], fn ($v) => is_string($v) && trim($v) !== '');
            $out[] = [
                'index' => (int) $index,
                'label' => $parts ? implode(' — ', array_unique(array_map('trim', $parts))) : 'Pickup address #' . $index,
                'raw'   => $item,
            ];
        }
        return $out;
    }
}
