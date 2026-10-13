<?php

namespace Tests\Support;

use App\Models\IntigoZone;
use App\Models\SellerPickupAddress;
use App\Models\User;

/**
 * Throwaway Intigo zones (intigo ids 99xxxxx, never Intigo's real ones) and
 * seller pickup addresses for tests.
 */
trait MakesPickupAddresses
{
    protected function pickupZone(string $type = IntigoZone::DELEGATION, string $name = 'Zone Test', string $delegation = 'Delegation Test', string $gouvernorat = 'Sfax', array $extra = []): IntigoZone
    {
        static $n = 0;
        $n++;
        $id = 9900000 + random_int(0, 89999) * 10 + ($n % 10);
        $name = $type === IntigoZone::DELEGATION ? $delegation : $name;

        return IntigoZone::create($extra + [
            'type'           => $type,
            'intigo_id'      => $id,
            'name'           => $name,
            'delegation_id'  => $extra['delegation_id'] ?? $id,
            'delegation'     => $delegation,
            'gouvernorat_id' => $extra['gouvernorat_id'] ?? 9900000,
            'gouvernorat'    => $gouvernorat,
            'wilaya'         => $gouvernorat,
            'display_label'  => $type === IntigoZone::SECTEUR ? "{$name}, {$delegation} ({$gouvernorat})" : "{$delegation} ({$gouvernorat})",
            'search_text'    => \App\Services\Intigo\IntigoZones::fold("{$name} {$delegation} {$gouvernorat}"),
            'latitude'       => 34.74,
            'longitude'      => 10.76,
            'geocode_precision' => $type === IntigoZone::SECTEUR ? 'secteur' : 'delegation',
            'geocoded_at'    => now(),
            'active'         => true,
        ]);
    }

    /** A complete pickup address; $ready = verified by the admin and created at Intigo. */
    protected function givePickup(User $seller, bool $ready = true, array $overrides = []): SellerPickupAddress
    {
        $zone = $overrides['zone'] ?? $this->pickupZone();
        unset($overrides['zone']);

        return SellerPickupAddress::updateOrCreate(['user_id' => $seller->id], $overrides + [
            'contact_name'              => 'Seller Contact',
            'phone'                     => '55111222',
            'adresse_complete'          => 'Route de Tunis km 5',
            'intigo_zone_id'            => $zone->id,
            'gouvernorat'               => $zone->gouvernorat,
            'code_postal'               => '3021',
            'latitude'                  => 34.7406,
            'longitude'                 => 10.7603,
            'needs_review'              => false,
            'change_pending'            => false,
            'address_verified_by_admin' => $ready,
            'verified_at'               => $ready ? now() : null,
            'intigo_pickup_created'     => $ready,
            'intigo_pickup_created_at'  => $ready ? now() : null,
        ]);
    }
}
