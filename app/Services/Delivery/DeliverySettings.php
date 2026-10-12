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
 *   delivery_size_rates (S, L, XL)  client_fee, agency_cost, return_fee, seller_contribution
 *   delivery_settings (row id 1)    return_shipping_fee (after-sale returns), who pays a
 *                                   refused parcel's return fee, intigo_can_open; its flat
 *                                   client / agency / contribution / refused columns mirror S
 *
 * Amounts are integer millimes. Cached forever, flushed on every update.
 * Orders snapshot the values at checkout, so an update never touches them.
 */
class DeliverySettings
{
    public const CACHE_KEY = 'delivery_settings.v2';

    /** Flat money fields (the S values, plus the after-sale return fee), in display order. */
    public const AMOUNTS = [
        'client_delivery_fee',
        'agency_delivery_cost',
        'seller_free_delivery_contribution',
        'return_shipping_fee',
        'refused_parcel_agency_fee',
    ];

    /** Per-size money fields. */
    public const SIZE_AMOUNTS = ['client_fee', 'agency_cost', 'return_fee', 'seller_contribution'];

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
     *   return_shipping_fee:int, refused_parcel_agency_fee:int, refused_parcel_fee_paid_by:string,
     *   intigo_can_open:bool, sizes:array<string, array{client_fee:int, agency_cost:int, return_fee:int, seller_contribution:int}>,
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

            $sizes = [];
            foreach (ParcelSize::ALL as $size) {
                $r = $rates->get($size);
                foreach (self::SIZE_AMOUNTS as $field) {
                    // A missing size row falls back to the flat (S) value
                    $sizes[$size][$field] = $r ? Millimes::of($r->{$field}) : Millimes::of($row->{array_search($field, self::FLAT_TO_SIZE, true)});
                }
            }

            $out = [];
            foreach (self::FLAT_TO_SIZE as $flat => $field) {
                $out[$flat] = $sizes['S'][$field];
            }
            $out['return_shipping_fee']        = Millimes::of($row->return_shipping_fee);
            $out['refused_parcel_fee_paid_by'] = $row->refused_parcel_fee_paid_by;
            $out['intigo_can_open']            = (bool) ($row->intigo_can_open ?? false);
            $out['sizes']                      = $sizes;
            $out['updated_at']                 = $row->updated_at;
            $out['updated_by']                 = $row->updated_by !== null ? (int) $row->updated_by : null;
            return $out;
        });
    }

    /** @return array{client_fee:int, agency_cost:int, return_fee:int, seller_contribution:int} */
    public function forSize(?string $size): array
    {
        return $this->all()['sizes'][ParcelSize::normalize($size)];
    }

    public function clientFee(?string $size = 'S'): int          { return $this->forSize($size)['client_fee']; }
    public function agencyCost(?string $size = 'S'): int         { return $this->forSize($size)['agency_cost']; }
    public function sellerContribution(?string $size = 'S'): int { return $this->forSize($size)['seller_contribution']; }
    /** What Intigo charges when a parcel of this size comes back (refused at the door). */
    public function refusedFee(?string $size = 'S'): int         { return $this->forSize($size)['return_fee']; }
    /** After-sale return pick-up (complaints), not size-based. */
    public function returnShippingFee(): int  { return $this->all()['return_shipping_fee']; }
    public function refusedFeePayer(): string { return $this->all()['refused_parcel_fee_paid_by']; }
    public function canOpen(): bool           { return $this->all()['intigo_can_open']; }

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
     * [size => [field => millimes]], refused_parcel_fee_paid_by and intigo_can_open.
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
                if (!$update) continue;
                if ($current) {
                    DB::table('delivery_size_rates')->where('size', $size)->update($update + ['updated_at' => $now]);
                } else {
                    $base = (array) ($rates->get('S') ?? []);
                    $insert = ['size' => $size, 'created_at' => $now, 'updated_at' => $now];
                    foreach (self::SIZE_AMOUNTS as $field) {
                        $insert[$field] = $update[$field] ?? ($base[$field] ?? '0.000');
                    }
                    DB::table('delivery_size_rates')->insert($insert);
                }
            }

            $flat = [];
            if (array_key_exists('return_shipping_fee', $values)) {
                $old = Millimes::of($row->return_shipping_fee);
                $new = (int) $values['return_shipping_fee'];
                if ($old !== $new) {
                    $flat['return_shipping_fee'] = Millimes::toDecimal($new);
                    $changes[] = ['field' => 'return_shipping_fee', 'old' => Millimes::toDecimal($old), 'new' => Millimes::toDecimal($new)];
                }
            }
            if (array_key_exists('refused_parcel_fee_paid_by', $values) && $values['refused_parcel_fee_paid_by'] !== $row->refused_parcel_fee_paid_by) {
                $flat['refused_parcel_fee_paid_by'] = $values['refused_parcel_fee_paid_by'];
                $changes[] = ['field' => 'refused_parcel_fee_paid_by', 'old' => $row->refused_parcel_fee_paid_by, 'new' => $values['refused_parcel_fee_paid_by']];
            }
            if (array_key_exists('intigo_can_open', $values) && (bool) $values['intigo_can_open'] !== (bool) $row->intigo_can_open) {
                $flat['intigo_can_open'] = (bool) $values['intigo_can_open'];
                $changes[] = ['field' => 'intigo_can_open', 'old' => $row->intigo_can_open ? 'yes' : 'no', 'new' => $values['intigo_can_open'] ? 'yes' : 'no'];
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
    }
}
