<?php

namespace Tests\Feature\Intigo;

use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Governorate / delegation dropdowns from Intigo's regions, and the artisan commands. */
class IntigoRegionsAndCommandsTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
    }

    private function regionAddress(int $city, int $district): array
    {
        return ['intigo_city_id' => $city, 'intigo_district_id' => $district] + $this->address();
    }

    public function test_checkout_requires_a_delegation_from_the_lists_once_synced(): void
    {
        $this->seedRegions();
        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40));
        $quote = $this->quote();
        $body  = ['payment_method' => 'cod', 'expected_total' => $quote['total']];

        // Free text only (an old saved address): reselect
        $this->as($this->customer)->postJson('/api/checkout', $this->address() + $body)->assertStatus(422)
            ->assertJsonValidationErrors(['intigo_city_id', 'intigo_district_id']);
        // A delegation of another governorate
        $this->as($this->customer)->postJson('/api/checkout', $this->regionAddress(23, 901) + $body)->assertStatus(422)
            ->assertJsonValidationErrors(['intigo_district_id']);

        $res = $this->as($this->customer)->postJson('/api/checkout', $this->regionAddress(11, 901) + $body)->assertCreated()->json();
        $order = DB::table('orders')->find($res['order_id']);
        $this->assertSame(['Kef', 'Kef Ouest', 'Le Kef', 'Kef Ouest', 11, 901], [$order->city_name, $order->district_name, $order->wilaya, $order->delegation, (int) $order->intigo_city_id, (int) $order->intigo_district_id]);

        $this->as($this->admin)->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $so = $this->parcel($order->id, $seller);
        $this->prepare($so, $seller);
        $this->assertSame(['Kef', 'Kef Ouest'], [$this->sentPayload($so)['city_name'], $this->sentPayload($so)['district_name']]);
    }

    /**
     * The storefront checkout, end to end: the city list loads cross-origin
     * from /api/regions/cities, the address is picked from it (no size in the
     * checkout payload: it comes from the product), and the parcel reaches
     * Intigo with Intigo's own city / delegation names and the product's size.
     */
    public function test_storefront_checkout_from_the_city_list_to_the_intigo_parcel(): void
    {
        $this->seedRegions();
        $this->get('/api/regions/cities', ['Origin' => 'http://localhost:3000', 'Accept' => 'application/json'])->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertJsonPath('available', true)->assertJsonFragment(['id' => 11, 'name' => 'Kef']);
        $this->getJson('/api/regions/cities/11/districts')->assertOk()->assertJsonPath('data.0.id', 901);

        $seller = $this->sellerWithPickup();
        $lamp   = $this->product($seller, 50);
        $lamp->update(['parcel_size' => 'L']);
        $this->addToCart($lamp);
        $quote = $this->quote();

        $res = $this->as($this->customer)->postJson('/api/checkout', $this->regionAddress(11, 901) + ['payment_method' => 'cod', 'expected_total' => $quote['total']])
            ->assertCreated()->json();
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $so = $this->parcel($res['order_id'], $seller);
        $this->prepare($so, $seller);

        $payload = $this->sentPayload($so);
        $this->assertSame(['Kef', 'Kef Ouest', 4], [$payload['city_name'], $payload['district_name'], $payload['package_size']]);
        $this->assertSame("FAKE-{$so->id}", $this->so($so)->intigo_nid, "fake mode: the parcel was created");
    }

    public function test_old_orders_without_intigo_names_fall_back_to_wilaya_and_delegation(): void
    {
        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40));
        [, $orderId] = $this->placeAndConfirm();   // regions not synced: free-text address
        $this->seedRegions();
        DB::table('orders')->where('id', $orderId)->update(['wilaya' => 'Le Kef', 'delegation' => 'Sakiet Sidi Youssef']);

        $so = $this->parcel($orderId, $seller);
        $this->prepare($so, $seller);
        $payload = $this->sentPayload($so);
        $this->assertSame('Kef', $payload['city_name'], "our 'Le Kef' sent as Intigo's 'Kef'");
        $this->assertSame('Sakiet Sidi Youssef', $payload['district_name']);
        $this->assertTrue($this->so($so)->intigo_district_fallback, 'unknown delegation: Intigo guesses');
    }

    public function test_the_address_book_stores_the_picked_region(): void
    {
        $this->seedRegions();
        $res = $this->as($this->customer)->postJson('/api/addresses', $this->regionAddress(23, 237) + ['label' => 'Bureau'])->assertSuccessful();
        $address = UserAddress::find($res->json('data.id'));
        $this->assertSame(['Tunis', 'Carthage', 23, 237], [$address->wilaya, $address->delegation, (int) $address->intigo_city_id, (int) $address->intigo_district_id]);

        $this->getJson('/api/regions/cities')->assertOk()->assertJsonPath('available', true)->assertJsonFragment(['name' => 'Kef', 'name_ar' => 'الكاف']);
        $this->assertCount(2, $this->getJson('/api/regions/cities/23/districts')->assertOk()->json('data'));
    }

    public function test_sync_regions_is_an_idempotent_upsert(): void
    {
        Http::fake([
            '*/regions/cities' => Http::response(['success' => true, 'cities' => [
                ['id' => 13, 'name' => 'Mannouba', 'name_ar' => 'منوبة'], ['id' => 23, 'name' => 'Tunis', 'name_ar' => 'تونس'],
            ]]),
            '*/regions/cities/13/districts' => Http::response(['success' => true, 'districts' => [['id' => 7001, 'name' => 'Douar Hicher', 'name_ar' => 'دوار هيشر', 'city_id' => 13]]]),
            '*/regions/cities/23/districts' => Http::response(['success' => true, 'districts' => [['id' => 235, 'name' => 'Bab Bhar', 'name_ar' => 'باب بحر', 'city_id' => 23]]]),
            '*' => Http::response(['detail' => 'unexpected'], 500),
        ]);

        $this->artisan('intigo:sync-regions')->assertExitCode(0);
        $this->artisan('intigo:sync-regions')->assertExitCode(0);

        $this->assertSame('La Manouba', DB::table('intigo_cities')->where('id', 13)->value('wilaya'));
        $this->assertSame(1, DB::table('intigo_districts')->where('id', 7001)->count());
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' || str_contains($r->url(), '/parcels'));
    }

    public function test_ping_is_read_only(): void
    {
        config(['intigo.api_key' => 'secret-test-key-123']);
        Http::fake([
            '*/regions/cities'     => Http::response(['success' => true, 'cities' => [['id' => 1, 'name' => 'Ariana']]]),
            '*/pickup-addresses/*' => Http::response(['success' => true, 'pickup_addresses' => [['index' => 0, 'address' => 'Dépôt']]]),
            '*'                    => Http::response([], 500),
        ]);

        $this->artisan('intigo:ping')->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/pickup-addresses/') && $r->header('X-API-Key') === ['secret-test-key-123']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        $this->assertStringNotContainsString('secret-test-key-123', DB::table('intigo_logs')->orderByDesc('id')->limit(5)->get()->toJson(), 'the key is never logged');
    }

    public function test_sync_statuses_polls_open_parcels_and_requeues_stuck_sends(): void
    {
        $client = $this->scripted();
        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40));
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);
        $this->prepare($so, $seller);
        $nid = $this->so($so)->intigo_nid;

        // A webhook missed: Intigo says out for delivery
        $client->statuses = [$nid => ['status' => 4000, 'status_label' => 'En cours de livraison']];
        $this->artisan('intigo:sync-statuses')->assertExitCode(0);
        $this->assertSame('out_for_delivery', $this->so($so)->status);
        $this->assertSame('poll', DB::table('intigo_webhook_events')->where('nid', $nid)->value('source'));

        // Polling again changes nothing (dedupe), delivered parcels are no longer polled
        $this->artisan('intigo:sync-statuses')->assertExitCode(0);
        $this->assertSame(1, DB::table('intigo_webhook_events')->where('nid', $nid)->count());
        $client->statuses = [$nid => ['status' => 5000, 'status_label' => 'Livré']];
        $this->artisan('intigo:sync-statuses')->assertExitCode(0);
        $this->assertSame('delivered', $this->so($so)->status);
        $polled = count($client->calls['statuses']);
        $this->artisan('intigo:sync-statuses')->assertExitCode(0);
        $this->assertCount($polled, $client->calls['statuses'], 'final parcels are not polled');

        // A send stuck in 'pending' (no worker) is queued again (sync queue in tests: sent now)
        $this->addToCart($this->product($seller, 30));
        [, $order2] = $this->placeAndConfirm();
        $stuck = $this->parcel($order2, $seller);
        DB::table('seller_orders')->where('id', $stuck->id)->update(['intigo_sync_status' => 'pending', 'updated_at' => now()->subHour()]);
        $this->artisan('intigo:sync-statuses')->assertExitCode(0);
        $this->assertSame('sent', $this->so($stuck)->intigo_sync_status);
    }

    public function test_an_unprocessed_event_is_processed_by_the_safety_net(): void
    {
        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40));
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);
        $this->prepare($so, $seller);
        $nid = $this->so($so)->intigo_nid;

        $sync = app(\App\Services\Intigo\IntigoStatusSync::class);
        $id = $sync->record($nid, 2000, null, '2026-10-12T09:00:00+01:00', 'Au dépôt', 'webhook');
        DB::table('intigo_webhook_events')->where('id', $id)->update(['created_at' => now()->subMinutes(5)]);

        $this->artisan('intigo:sync-statuses')->assertExitCode(0);
        $this->assertSame('handed_to_courier', $this->so($so)->status);
        $this->assertNotNull(DB::table('intigo_webhook_events')->where('id', $id)->value('processed_at'));
    }
}
