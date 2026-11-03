<?php

namespace App\Services\Delivery;

use App\Support\Millimes;
use App\Support\ParcelSize;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin-controlled delivery pricing: the single source of every delivery
 * amount (checkout, finance, returns, refused parcels, Intigo). No fee lives
 * in .env or in code.
 *
 *   delivery_size_rates (S, L, XL)  client_fee, agency_cost, return_fee (refused parcel),
 *                                   seller_contribution, after-sale fees: after_sale_return_fee
 *                                   (follows agency_cost while after_sale_use_agency_cost is on)
 *                                   and exchange_fee (Intigo's exchange parcel price)
 *   delivery_settings (row id 1)    who pays a refused parcel's return fee, intigo_can_open,
 *                                   intigo_is_exchange (both sent with every new Intigo parcel);
 *                                   its flat client / agency / contribution / refused columns
 *                                   mirror S (its old return_shipping_fee is no longer read)
 *
 * Amounts are integer millimes. Cached forever, flushed on every update.
 * Orders snapshot the values at checkout, so an update never touches them.
 */
class DeliverySettings
{
    public const CACHE_KEY = 'delivery_settings.v4';

    /** Flat money fields (the S values), in display order. */
    public const AMOUNTS = [
        'client_delivery_fee',
        'agency_delivery_cost',
        'seller_free_delivery_contribution',
        'refused_parcel_agency_fee',
    ];

    /** Per-size delivery money fields. */
    public const SIZE_AMOUNTS = ['client_fee', 'agency_cost', 'return_fee', 'seller_contribution'];

    /** Per-size after-sale money fields (complaints). */
    public const AFTER_SALE_AMOUNTS = ['after_sale_return_fee', 'exchange_fee'];

    /** Per-size switch: the after-sale return fee follows the Intigo delivery cost. */
    public const AFTER_SALE_SYNC = 'after_sale_use_agency_cost';

    /** Flat field → its per-size field (written on S). */
    private const FLAT_TO_SIZE = [
        'client_delivery_fee'               => 'client_fee',
        'agency_delivery_cost'              => 'agency_cost',
        'seller_free_delivery_contribution' => 'seller_contribution',
        'refused_parcel_agency_fee'         => 'return_fee',
    ];

    public const PAYERS = ['platform', 'seller'];

    /**
     * @return array{client_delivery_fee:int, agency_delivery_cost:int, seller_free_delivery_contribution:int,
     *   refused_parcel_agency_fee:int, refused_parcel_fee_paid_by:string, intigo_can_open:bool, intigo_is_exchange:bool,
     *   sizes:array<string, array{client_fee:int, agency_cost:int, return_fee:int, seller_contribution:int,
     *   after_sale_return_fee:int, exchange_fee:int}>, after_sale_sync:array<string, bool>,
     *   updated_at:?string, updated_by:?int}
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $row = DB::table('delivery_settings')->where('id', 1)->first();
            if (!$row) {
                throw new \RuntimeException('delivery_settings row missing: run the migrations.');
            }
            $rates = DB::table('delivery_size_rates')->get()->keyBy('size');

            $sizes = $sync = [];
            foreach (ParcelSize::ALL as $size) {
                $r = $rates->get($size);
                foreach (self::SIZE_AMOUNTS as $field) {
                    // A missing size row falls back to the flat (S) value
                    $sizes[$size][$field] = $r ? Millimes::of($r->{$field}) : Millimes::of($row->{array_search($field, self::FLAT_TO_SIZE, true)});
                }
                // After-sale fees (no row / no column yet: the delivery cost)
                $sync[$size] = !isset($r->after_sale_use_agency_cost) || (bool) $r->after_sale_use_agency_cost;
                $sizes[$size]['after_sale_return_fee'] = $sync[$size] || !isset($r->after_sale_return_fee)
                    ? $sizes[$size]['agency_cost']
                    : Millimes::of($r->after_sale_return_fee);
                $sizes[$size]['exchange_fee'] = isset($r->exchange_fee) ? Millimes::of($r->exchange_fee) : $sizes[$size]['agency_cost'];
            }

            $out = [];
            foreach (self::FLAT_TO_SIZE as $flat => $field) {
                $out[$flat] = $sizes['S'][$field];
            }
            $out['refused_parcel_fee_paid_by'] = $row->refused_parcel_fee_paid_by;
            $out['intigo_can_open']            = (bool) ($row->intigo_can_open ?? false);
            $out['intigo_is_exchange']         = (bool) ($row->intigo_is_exchange ?? false);
            $out['sizes']                      = $sizes;
            $out['after_sale_sync']            = $sync;
            $out['updated_at']                 = $row->updated_at;
            $out['updated_by']                 = $row->updated_by !== null ? (int) $row->updated_by : null;
            return $out;
        });
    }

    /** @return array{client_fee:int, agency_cost:int, return_fee:int, seller_contribution:int, after_sale_return_fee:int, exchange_fee:int} */
    public function forSize(?string $size): array
    {
        return $this->all()['sizes'][ParcelSize::normalize($size)];
    }

    public function clientFee(?string $size = 'S'): int          { return $this->forSize($size)['client_fee']; }
    public function agencyCost(?string $size = 'S'): int         { return $this->forSize($size)['agency_cost']; }
    public function sellerContribution(?string $size = 'S'): int { return $this->forSize($size)['seller_contribution']; }
    /** What Intigo charges when a parcel of this size comes back (refused at the door). */
    public function refusedFee(?string $size = 'S'): int         { return $this->forSize($size)['return_fee']; }
    /** After-sale (complaint) return of a parcel of this size: Intigo bills it as a new parcel. */
    public function afterSaleReturnFee(?string $size = 'S'): int { return $this->forSize($size)['after_sale_return_fee']; }
    /** What Intigo bills for an exchange parcel of this size. */
    public function exchangeFee(?string $size = 'S'): int        { return $this->forSize($size)['exchange_fee']; }
    public function refusedFeePayer(): string { return $this->all()['refused_parcel_fee_paid_by']; }
    public function canOpen(): bool           { return $this->all()['intigo_can_open']; }
    /** Intigo is_exchange flag sent with every new parcel. */
    public function isExchange(): bool        { return $this->all()['intigo_is_exchange'] ?? false; }

    /** Platform delivery margin per shipment, per size, for the admin form and reports. */
    public function margins(?array $values = null): array
    {
        $v   = $values ?? $this->all();
        $out = [];
        foreach ($v['sizes'] as $size => $r) {
            $out[$size] = [
                'normal'        => $r['client_fee'] - $r['agency_cost'],
                'free_delivery' => $r['seller_contribution'] - $r['agency_cost'],
            ];
        }
        // Flat S figures, as before
        return $out['S'] + ['sizes' => $out];
    }

    /**
     * Save new values (millimes for amounts) and log every changed field.
     * Validation (no negatives, max 3 decimals) is the controller's job.
     *
     * $values may hold the flat fields (applied to size S), 'sizes' =>
     * [size => [field => millimes]], refused_parcel_fee_paid_by, intigo_can_open and intigo_is_exchange.
     *
     * @return array<int, array{field:string, old:string, new:string}> the changes made
     */
    public function update(array $values, ?int $adminId): array
    {
        $changes = DB::transaction(function () use ($values, $adminId) {
            $row   = DB::table('delivery_settings')->where('id', 1)->lockForUpdate()->first();
            $rates = DB::table('delivery_size_rates')->lockForUpdate()->get()->keyBy('size');

            // Flat fields are the S values
            $sizes = $values['sizes'] ?? [];
            foreach (self::FLAT_TO_SIZE as $flat => $field) {
                if (array_key_exists($flat, $values) && !isset($sizes['S'][$field])) {
                    $sizes['S'][$field] = $values[$flat];
                }
            }

            $now     = now();
            $changes = [];
            foreach ($sizes as $size => $fields) {
                if (!ParcelSize::isValid($size)) continue;
                $current = $rates->get($size);
                $update  = [];
                foreach (self::SIZE_AMOUNTS as $field) {
                    if (!array_key_exists($field, $fields)) continue;
                    $old = $current ? Millimes::of($current->{$field}) : null;
                    $new = (int) $fields[$field];
                    if ($old !== $new) {
                        $update[$field] = Millimes::toDecimal($new);
                        $changes[] = ['field' => "{$size}.{$field}", 'old' => $old === null ? null : Millimes::toDecimal($old), 'new' => Millimes::toDecimal($new)];
                    }
                }
                $update += $this->afterSaleUpdate($size, $fields, $current, $update, $changes);
                if (!$update) continue;
                if ($current) {
                    DB::table('delivery_size_rates')->where('size', $size)->update($update + ['updated_at' => $now]);
                } else {
                    $base = (array) ($rates->get('S') ?? []);
                    $insert = ['size' => $size, 'created_at' => $now, 'updated_at' => $now];
                    foreach (self::SIZE_AMOUNTS as $field) {
                        $insert[$field] = $update[$field] ?? ($base[$field] ?? '0.000');
                    }
                    foreach ([...self::AFTER_SALE_AMOUNTS, self::AFTER_SALE_SYNC] as $field) {
                        if (array_key_exists($field, $update)) $insert[$field] = $update[$field];
                    }
                    DB::table('delivery_size_rates')->insert($insert);
                }
            }

            $flat = [];
            if (array_key_exists('refused_parcel_fee_paid_by', $values) && $values['refused_parcel_fee_paid_by'] !== $row->refused_parcel_fee_paid_by) {
                $flat['refused_parcel_fee_paid_by'] = $values['refused_parcel_fee_paid_by'];
                $changes[] = ['field' => 'refused_parcel_fee_paid_by', 'old' => $row->refused_parcel_fee_paid_by, 'new' => $values['refused_parcel_fee_paid_by']];
            }
            if (array_key_exists('intigo_can_open', $values) && (bool) $values['intigo_can_open'] !== (bool) $row->intigo_can_open) {
                $flat['intigo_can_open'] = (bool) $values['intigo_can_open'];
                $changes[] = ['field' => 'intigo_can_open', 'old' => $row->intigo_can_open ? 'yes' : 'no', 'new' => $values['intigo_can_open'] ? 'yes' : 'no'];
            }
            if (array_key_exists('intigo_is_exchange', $values) && property_exists($row, 'intigo_is_exchange')
                && (bool) $values['intigo_is_exchange'] !== (bool) $row->intigo_is_exchange) {
                $flat['intigo_is_exchange'] = (bool) $values['intigo_is_exchange'];
                $changes[] = ['field' => 'intigo_is_exchange', 'old' => $row->intigo_is_exchange ? 'yes' : 'no', 'new' => $values['intigo_is_exchange'] ? 'yes' : 'no'];
            }

            if ($changes) {
                // Keep the flat columns as the S mirror
                $s = DB::table('delivery_size_rates')->where('size', 'S')->first();
                if ($s) {
                    foreach (self::FLAT_TO_SIZE as $flatField => $field) {
                        $flat[$flatField] = $s->{$field};
                    }
                }
                DB::table('delivery_settings')->where('id', 1)->update($flat + ['updated_by' => $adminId, 'updated_at' => $now]);
                $this->log($changes, $adminId);
            }
            return $changes;
        });

        $this->flush();
        return $changes;
    }

    /**
     * After-sale columns of one size row. While the sync switch is on, the
     * stored after-sale return fee follows the (new) Intigo delivery cost.
     */
    private function afterSaleUpdate(string $size, array $fields, ?object $current, array $deliveryUpdate, array &$changes): array
    {
        $update = [];
        $has    = fn (string $col) => $current && property_exists($current, $col);

        if (array_key_exists(self::AFTER_SALE_SYNC, $fields)) {
            $old = $has(self::AFTER_SALE_SYNC) ? (bool) $current->{self::AFTER_SALE_SYNC} : true;
            $new = (bool) $fields[self::AFTER_SALE_SYNC];
            if ($old !== $new) {
                $update[self::AFTER_SALE_SYNC] = $new;
                $changes[] = ['field' => "{$size}." . self::AFTER_SALE_SYNC, 'old' => $old ? 'yes' : 'no', 'new' => $new ? 'yes' : 'no'];
            }
        }
        $sync = $update[self::AFTER_SALE_SYNC] ?? ($has(self::AFTER_SALE_SYNC) ? (bool) $current->{self::AFTER_SALE_SYNC} : true);

        $agency = isset($deliveryUpdate['agency_cost'])
            ? Millimes::of($deliveryUpdate['agency_cost'])
            : ($current ? Millimes::of($current->agency_cost) : null);
        $wanted = [
            'after_sale_return_fee' => $sync ? $agency : (array_key_exists('after_sale_return_fee', $fields) ? (int) $fields['after_sale_return_fee'] : null),
            'exchange_fee'          => array_key_exists('exchange_fee', $fields) ? (int) $fields['exchange_fee'] : null,
        ];
        foreach ($wanted as $field => $new) {
            if ($new === null || !$has($field) && $current) continue;   // column not migrated yet
            $old = $has($field) ? Millimes::of($current->{$field}) : null;
            if ($old !== $new) {
                $update[$field] = Millimes::toDecimal($new);
                $changes[] = ['field' => "{$size}.{$field}", 'old' => $old === null ? null : Millimes::toDecimal($old), 'new' => Millimes::toDecimal($new)];
            }
        }
        return $update;
    }

    /** Audit rows (also used for payment-method switches). */
    public function log(array $changes, ?int $adminId): void
    {
        $now = now();
        DB::table('delivery_setting_changes')->insert(array_map(fn ($c) => [
            'field'      => $c['field'],
            'old_value'  => $c['old'],
            'new_value'  => $c['new'],
            'changed_by' => $adminId,
            'created_at' => $now,
        ], $changes));
    }

    public function history(int $limit = 100): array
    {
        return DB::table('delivery_setting_changes as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.changed_by')
            ->orderByDesc('c.id')
            ->limit($limit)
            ->get(['c.id', 'c.field', 'c.old_value', 'c.new_value', 'c.created_at', 'c.changed_by', 'u.name as changed_by_name'])
            ->all();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('delivery_settings.v1');
        Cache::forget('delivery_settings.v2');
        Cache::forget('delivery_settings.v3');
    }
}
