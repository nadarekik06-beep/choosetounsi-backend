<?php

namespace App\Services\Delivery;

use App\Support\Millimes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin-controlled delivery pricing (table delivery_settings, row id 1).
 * The single source of every delivery amount: checkout, finance, returns and
 * refused parcels read it here; no fee lives in .env or in code.
 *
 * Amounts are integer millimes. Cached forever, flushed on every update.
 * Orders snapshot the values at checkout, so an update never touches them.
 */
class DeliverySettings
{
    public const CACHE_KEY = 'delivery_settings.v1';

    /** Money fields, in display order. */
    public const AMOUNTS = [
        'client_delivery_fee',
        'agency_delivery_cost',
        'seller_free_delivery_contribution',
        'return_shipping_fee',
        'refused_parcel_agency_fee',
    ];

    public const PAYERS = ['platform', 'seller'];

    /** @return array{client_delivery_fee:int, agency_delivery_cost:int, seller_free_delivery_contribution:int, return_shipping_fee:int, refused_parcel_agency_fee:int, refused_parcel_fee_paid_by:string, updated_at:?string, updated_by:?int} */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $row = DB::table('delivery_settings')->where('id', 1)->first();
            if (!$row) {
                throw new \RuntimeException('delivery_settings row missing: run the migrations.');
            }
            $out = [];
            foreach (self::AMOUNTS as $field) {
                $out[$field] = Millimes::of($row->{$field});
            }
            $out['refused_parcel_fee_paid_by'] = $row->refused_parcel_fee_paid_by;
            $out['updated_at'] = $row->updated_at;
            $out['updated_by'] = $row->updated_by !== null ? (int) $row->updated_by : null;
            return $out;
        });
    }

    public function clientFee(): int        { return $this->all()['client_delivery_fee']; }
    public function agencyCost(): int       { return $this->all()['agency_delivery_cost']; }
    public function sellerContribution(): int { return $this->all()['seller_free_delivery_contribution']; }
    public function returnShippingFee(): int { return $this->all()['return_shipping_fee']; }
    public function refusedFee(): int       { return $this->all()['refused_parcel_agency_fee']; }
    public function refusedFeePayer(): string { return $this->all()['refused_parcel_fee_paid_by']; }

    /** Platform delivery margin per shipment, for the admin form and reports. */
    public function margins(?array $values = null): array
    {
        $v = $values ?? $this->all();
        return [
            'normal'        => $v['client_delivery_fee'] - $v['agency_delivery_cost'],
            'free_delivery' => $v['seller_free_delivery_contribution'] - $v['agency_delivery_cost'],
        ];
    }

    /**
     * Save new values (millimes for amounts) and log every changed field.
     * Validation (no negatives, max 3 decimals) is the controller's job.
     *
     * @param array<string, int|string> $values
     * @return array<int, array{field:string, old:string, new:string}> the changes made
     */
    public function update(array $values, ?int $adminId): array
    {
        $changes = DB::transaction(function () use ($values, $adminId) {
            $row = DB::table('delivery_settings')->where('id', 1)->lockForUpdate()->first();
            $update  = [];
            $changes = [];

            foreach (self::AMOUNTS as $field) {
                if (!array_key_exists($field, $values)) continue;
                $old = Millimes::of($row->{$field});
                $new = (int) $values[$field];
                if ($old !== $new) {
                    $update[$field] = Millimes::toDecimal($new);
                    $changes[] = ['field' => $field, 'old' => Millimes::toDecimal($old), 'new' => Millimes::toDecimal($new)];
                }
            }
            if (array_key_exists('refused_parcel_fee_paid_by', $values) && $values['refused_parcel_fee_paid_by'] !== $row->refused_parcel_fee_paid_by) {
                $update['refused_parcel_fee_paid_by'] = $values['refused_parcel_fee_paid_by'];
                $changes[] = ['field' => 'refused_parcel_fee_paid_by', 'old' => $row->refused_parcel_fee_paid_by, 'new' => $values['refused_parcel_fee_paid_by']];
            }

            if ($update) {
                DB::table('delivery_settings')->where('id', 1)->update($update + ['updated_by' => $adminId, 'updated_at' => now()]);
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
    }
}
