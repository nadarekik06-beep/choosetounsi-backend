<?php

namespace Tests\Feature\Seller;

use App\Models\IntigoZone;
use App\Models\SellerAddressChange;
use App\Models\SellerApplication;
use App\Models\SellerPickupAddress;
use App\Models\User;
use App\Notifications\Sellers\SellerPickupChangedNotification;
use App\Services\Sellers\PickupAddressService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;
use Tests\TestCase;

/**
 * Seller pickup address, 1:1 with Intigo's "Nouvelle adresse" form:
 * apply → admin sees it → verified → created in Intigo → seller edits it →
 * admin notified, flags reset, history logged, parcels held until re-created.
 * Fake mode only (ScriptedIntigoClient): nothing reaches Intigo.
 */
class PickupAddressFlowTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private IntigoZone $secteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        $this->secteur = $this->pickupZone(IntigoZone::SECTEUR, 'Aéroport Testville Carthage', 'La Soukra Test', 'Ariana',
            ['latitude' => 36.8736, 'longitude' => 10.2379]);
    }

    private function application(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => 'Amel Ben Ali', 'phone_number' => '22 123 456', 'business_name' => 'Atelier ' . Str::random(5),
            'business_category' => 'crafts',
            'contact_name' => 'Amel Ben Ali', 'phone' => '22123456',
            'adresse_complete' => '12 Rue des Fleurs, Imm. Jasmin, Apt 4',
            'intigo_zone_id' => $this->secteur->id, 'code_postal' => '2035',
            'latitude' => 36.8741, 'longitude' => 10.2391, 'indications' => 'Sonner au 2e étage',
        ];
    }

    public function test_zone_search_is_partial_accent_insensitive_and_returns_the_badge(): void
    {
        foreach (['aeroport testv', 'AÉROPORT', 'testville carth', 'soukra test'] as $q) {
            $res = $this->getJson('/api/intigo-zones?q=' . urlencode($q))->assertOk();
            $hit = collect($res->json('data'))->firstWhere('id', $this->secteur->id);
            $this->assertNotNull($hit, "\"{$q}\" finds the secteur");
            $this->assertSame('SECTEUR', $hit['type']);
            $this->assertSame('Aéroport Testville Carthage, La Soukra Test (Ariana)', $hit['display_label']);
        }
        $this->assertLessThanOrEqual(3, count($this->getJson('/api/intigo-zones?q=a&limit=3')->json('data')));
        $this->assertSame([], $this->getJson('/api/intigo-zones?q=')->json('data'));
    }

    public function test_application_requires_a_zone_from_the_list_and_a_pin_in_tunisia(): void
    {
        $user = $this->makeUser('client');

        $this->as($user)->postJson('/api/seller-applications', $this->application(['latitude' => null, 'longitude' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude']);
        $this->as($user)->postJson('/api/seller-applications', $this->application(['intigo_zone_id' => 'La Soukra']))
            ->assertStatus(422)->assertJsonValidationErrors('intigo_zone_id');
        $this->as($user)->postJson('/api/seller-applications', $this->application(['latitude' => 48.85, 'longitude' => 2.35]))
            ->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude']);
        $inactive = $this->pickupZone(extra: ['active' => false]);
        $this->as($user)->postJson('/api/seller-applications', $this->application(['intigo_zone_id' => $inactive->id]))
            ->assertStatus(422)->assertJsonValidationErrors('intigo_zone_id');

        $res = $this->as($user)->postJson('/api/seller-applications', $this->application())->assertCreated();
        $res->assertJsonPath('data.pickup.zone.display_label', $this->secteur->display_label)
            ->assertJsonPath('data.pickup.status', 'unverified');

        $app = SellerApplication::where('user_id', $user->id)->first();
        $this->assertSame(['Ariana', 'La Soukra Test'], [$app->wilaya, $app->city], 'wilaya / city derived from the zone');
        $pickup = SellerPickupAddress::where('user_id', $user->id)->first();
        $this->assertSame('Ariana', $pickup->gouvernorat);
        $this->assertFalse($pickup->address_verified_by_admin);
        $this->assertFalse($pickup->change_pending);
        $this->assertSame(1, SellerAddressChange::where('user_id', $user->id)->count());
    }

    public function test_full_flow_from_application_to_intigo_and_address_change(): void
    {
        $intigo = $this->scripted();
        $admin  = $this->admin;
        $user   = $this->makeUser('client');
        $this->as($user)->postJson('/api/seller-applications', $this->application())->assertCreated();
        $app = SellerApplication::where('user_id', $user->id)->first();

        // Admin review page shows the same card, in the Intigo order
        $this->as($admin)->getJson("/api/admin/seller-applications/{$app->id}")->assertOk()
            ->assertJsonPath('data.pickup.adresse_complete', '12 Rue des Fleurs, Imm. Jasmin, Apt 4')
            ->assertJsonPath('data.pickup.zone.type', 'SECTEUR')
            ->assertJsonPath('data.pickup.google_maps_url', 'https://www.google.com/maps/search/?api=1&query=36.8741,10.2391')
            ->assertJsonPath('data.pickup.pin_check.far', false);

        // A legacy applicant without zone / pin can't be approved
        $legacy = $this->makeUser('client');
        $legacyApp = SellerApplication::create([
            'user_id' => $legacy->id, 'full_name' => 'Old Shop', 'phone_number' => '55000111', 'business_name' => 'Old',
            'business_category' => 'crafts', 'wilaya' => 'Tunis', 'city' => 'Tunis', 'status' => 'pending',
        ]);
        $this->as($admin)->postJson("/api/admin/seller-applications/{$legacyApp->id}/approve")->assertStatus(422);
        $this->as($admin)->postJson("/api/admin/seller-applications/{$app->id}/approve")->assertOk();
        $seller = $user->fresh();
        $this->assertSame('seller', $seller->role);

        // "Créer dans Intigo" needs "Adresse vérifiée" first
        $this->as($admin)->postJson("/api/admin/sellers/{$seller->id}/pickup/intigo-create")->assertStatus(422);
        $this->as($admin)->postJson("/api/admin/sellers/{$seller->id}/pickup/verify")->assertOk()
            ->assertJsonPath('data.pickup.status', 'verified');
        $this->as($admin)->postJson("/api/admin/sellers/{$seller->id}/pickup/intigo-create")->assertOk()
            ->assertJsonPath('data.pickup.status', 'created')
            ->assertJsonPath('data.pickup.intigo_pickup_index', 41);

        $body = $intigo->calls['createPickupAddress'][0];
        $this->assertSame('12 Rue des Fleurs, Imm. Jasmin, Apt 4 — Contact : Amel Ben Ali +216 22 123 456', $body['street_address']);
        $this->assertSame([$this->secteur->gouvernorat_id, $this->secteur->delegation_id, $this->secteur->intigo_id],
            [$body['city_id'], $body['district_id'], $body['neighborhood_id']]);
        $this->assertSame([36.8741, 10.2391], [$body['latitude'], $body['longitude']]);
        $this->assertSame(41, (int) $seller->fresh()->intigo_pickup_index);

        // A parcel waiting for pickup, then the seller moves
        $product = $this->product($seller, 50);
        $this->addToCart($product);
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);

        Notification::fake();
        $newZone = $this->pickupZone(IntigoZone::DELEGATION, '', 'Bab Bhar Test', 'Tunis', ['latitude' => 36.8008, 'longitude' => 10.1993]);
        $this->as($seller)->putJson('/api/seller/pickup-address', [
            'adresse_complete' => '5 Avenue de France', 'intigo_zone_id' => $newZone->id, 'latitude' => 36.7990, 'longitude' => 10.1800,
        ] + $this->application())->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('data.status', 'changed')
            ->assertJsonPath('data.intigo_ready', false);

        $record = SellerPickupAddress::where('user_id', $seller->id)->first();
        $this->assertFalse($record->address_verified_by_admin);
        $this->assertFalse($record->intigo_pickup_created);
        $this->assertTrue($record->change_pending);
        $this->assertNotNull($record->last_address_change_at);

        $change = SellerAddressChange::where('user_id', $seller->id)->latest('id')->first();
        $this->assertSame('settings', $change->source);
        $this->assertSame(['adresse_complete', 'intigo_zone_id', 'latitude', 'longitude'], $change->changed_fields);
        $this->assertSame('12 Rue des Fleurs, Imm. Jasmin, Apt 4', $change->old_values['adresse_complete']);
        $this->assertSame('Bab Bhar Test (Tunis)', $change->new_values['zone_label']);
        $this->assertSame(1, $change->pending_pickups);

        Notification::assertSentTo($admin, SellerPickupChangedNotification::class, function ($n) use ($admin, $seller) {
            $db = $n->toDatabase($admin);
            $mail = $n->toMail($admin);
            return $db['link'] === "/sellers/{$seller->id}"
                && str_contains($db['title'], 'Adresse de pickup modifiée')
                && $db['data']['pending_pickups'] === 1
                && str_contains(implode(' ', $mail->introLines), "1 commande(s) pas encore enlevée(s)");
        });

        // Sellers list: badge + filter
        $this->as($admin)->getJson('/api/admin/sellers?pickup_status=changed&per_page=100')->assertOk()
            ->assertJsonFragment(['id' => $seller->id, 'pickup_status' => 'changed']);
        $this->assertNotContains($seller->id, collect($this->as($admin)->getJson('/api/admin/sellers?pickup_status=created&per_page=100')->json('data.data'))->pluck('id'));

        // Prepared while the new address isn't at Intigo: held, never sent to the old one
        $this->prepare($so, $seller);
        $held = $this->so($so);
        $this->assertNull($held->intigo_nid);
        $this->assertSame('failed', $held->intigo_sync_status);
        $this->assertStringContainsString(PickupAddressService::HOLD, $held->intigo_error);
        $this->assertCount(0, $intigo->calls['create']);

        // Admin verifies and updates Intigo: same index (PATCH), held parcel sent
        $this->as($admin)->postJson("/api/admin/sellers/{$seller->id}/pickup/verify")->assertOk();
        $this->as($admin)->postJson("/api/admin/sellers/{$seller->id}/pickup/intigo-create")->assertOk()
            ->assertJsonPath('data.pickup.status', 'created');
        $this->assertSame(41, $intigo->calls['updatePickupAddress'][0][0]);
        $this->assertStringStartsWith('5 Avenue de France', $intigo->calls['updatePickupAddress'][0][1]['street_address']);
        $this->assertNull($intigo->calls['updatePickupAddress'][0][1]['neighborhood_id'], 'a délégation has no quartier');

        $sent = $this->so($so);
        $this->assertNotNull($sent->intigo_nid, 'held parcel re-sent once the address is created');
        $this->assertSame(41, $intigo->calls['create'][0]['pickup_index']);

        // History timeline for the admin, newest first
        $history = $this->as($admin)->getJson("/api/admin/sellers/{$seller->id}/pickup")->assertOk()->json('data.history');
        $this->assertSame(['settings', 'application'], array_column($history, 'source'));
    }

    public function test_no_change_is_not_logged_and_manual_index_requires_verification(): void
    {
        $seller = $this->seller();
        $record = SellerPickupAddress::where('user_id', $seller->id)->first();
        $values = $record->only(SellerPickupAddress::FIELDS);

        Notification::fake();
        $this->as($seller)->putJson('/api/seller/pickup-address', $values)->assertOk()->assertJsonPath('changed', false);
        Notification::assertNothingSent();
        $this->assertSame(0, SellerAddressChange::where('user_id', $seller->id)->count());

        $record->update(['address_verified_by_admin' => false]);
        $this->as($this->admin)->postJson("/api/admin/sellers/{$seller->id}/pickup/intigo-manual", ['index' => 7])->assertStatus(422);
        $this->as($this->admin)->postJson("/api/admin/sellers/{$seller->id}/pickup/verify")->assertOk();
        $this->as($this->admin)->postJson("/api/admin/sellers/{$seller->id}/pickup/intigo-manual", ['index' => 7, 'label' => 'Atelier X'])
            ->assertOk()->assertJsonPath('data.pickup.intigo_pickup_created_via', 'manual')
            ->assertJsonPath('data.pickup.intigo_pickup_index', 7);
    }

    public function test_migrated_sellers_need_review_and_are_held(): void
    {
        $seller = $this->seller();
        SellerPickupAddress::where('user_id', $seller->id)->update([
            'adresse_complete' => null, 'intigo_zone_id' => null, 'latitude' => null, 'longitude' => null,
            'needs_review' => true, 'address_verified_by_admin' => false, 'intigo_pickup_created' => false,
        ]);
        $seller->forceFill(['intigo_pickup_index' => 3])->save();

        $this->as($this->admin)->getJson('/api/admin/sellers?pickup_status=needs_review&per_page=100')
            ->assertJsonFragment(['id' => $seller->id, 'pickup_status' => 'needs_review']);
        $this->as($this->admin)->postJson("/api/admin/sellers/{$seller->id}/pickup/verify")->assertStatus(422);

        $this->addToCart($this->product($seller, 30));
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);
        $this->prepare($so, $seller);
        $this->assertNull($this->so($so)->intigo_nid);
        $this->assertStringContainsString(PickupAddressService::HOLD, $this->so($so)->intigo_error);
    }
}
