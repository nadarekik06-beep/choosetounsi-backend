<?php

namespace Tests\Feature\Intigo;

use App\Mail\IntigoAlertMail;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Intigo\PickupVerifier;
use App\Services\Sellers\PickupAddressService;
use App\Support\TestAccounts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The last line of defence before a real driver moves: right seller pickup,
 * right client address (checkout snapshot), right COD, and nothing sent when
 * any of it is doubtful — the admin is told (e-mail + bell) and the order
 * stays as it is for "Retry send".
 * Live mode is simulated with ScriptedIntigoClient: no network.
 */
class IntigoSafetyTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private const TUNIS_BAB_BHAR = ['intigo_city_id' => 23, 'intigo_district_id' => 235];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        config(['intigo.alert_email' => 'alerts@test.local']);
        TestAccounts::flush();
        PickupVerifier::forget();
        Mail::fake();
    }

    private function live(): ScriptedIntigoClient
    {
        $client = $this->scripted();
        config(['intigo.mode' => 'live']);
        $this->seedRegions();
        return $client;
    }

    /** One confirmed parcel of $seller, the client address picked from Intigo's lists. */
    private function order(User $seller, $price = 40): array
    {
        $this->addToCart($this->product($seller, $price));
        [, $orderId] = $this->placeAndConfirm(self::TUNIS_BAB_BHAR);
        return [$this->parcel($orderId, $seller), $orderId];
    }

    private function assertRefused(object $so, string $reason, ScriptedIntigoClient $client): void
    {
        $fresh = $this->so($so);
        $this->assertCount(0, $client->calls['create'], 'no parcel created');
        $this->assertNull($fresh->intigo_nid);
        $this->assertSame('failed', $fresh->intigo_sync_status);
        $this->assertStringContainsString($reason, $fresh->intigo_error);
        // The order is kept as it is, ready for "Retry send"
        $this->assertSame('confirmed', $fresh->status);
        $this->assertNotNull($fresh->prepared_at);
        $this->assertSame('confirmed', DB::table('orders')->where('id', $fresh->order_id)->value('status'));
        // Admin told: e-mail + bell, with the reason
        Mail::assertQueued(IntigoAlertMail::class, fn ($m) => $m->hasTo('alerts@test.local'));
        $this->assertTrue(DB::table('notifications')->where('notifiable_id', $this->admin->id)
            ->where('data', 'like', '%Parcel not sent to Intigo%')->exists(), 'bell');
    }

    // ── TEST accounts ──────────────────────────────────────────────────────

    public function test_a_test_sellers_order_is_never_sent_in_live_mode(): void
    {
        $client = $this->live();
        $seller = $this->sellerWithPickup(3);
        $seller->forceFill(['email' => 'choosetounsi+testseller' . Str::random(4) . '@gmail.com'])->save();
        [$so] = $this->order($seller);

        $this->prepare($so, $seller);
        $this->assertRefused($so, 'TEST seller account', $client);
    }

    public function test_a_test_buyers_order_is_never_sent_in_live_mode(): void
    {
        $client = $this->live();
        $this->customer->forceFill(['email' => 'CHOOSETOUNSI+testbuyer' . Str::random(4) . '@gmail.com'])->save();
        $seller = $this->sellerWithPickup(3);
        [$so] = $this->order($seller);

        $this->prepare($so, $seller);
        $this->assertRefused($so, 'TEST buyer account', $client);
    }

    public function test_test_accounts_by_id_and_real_accounts_pass(): void
    {
        config(['intigo.test_accounts.user_ids' => (string) $this->customer->id]);
        $this->assertTrue(TestAccounts::isTestUser($this->customer->id));
        $this->assertFalse(TestAccounts::isTestUser($this->admin->id));
        $this->assertFalse(TestAccounts::isTestEmail('choosetounsi@gmail.com'));
        $this->assertFalse(TestAccounts::isTestEmail('someone+test@gmail.com'));
        $this->assertTrue(TestAccounts::isTestEmail('choosetounsi+testadmin@gmail.com'));
    }

    // ── Pickup: the seller's own, existing at Intigo ────────────────────────

    public function test_an_index_missing_at_intigo_blocks_the_parcel_until_it_exists_then_retry_sends_it(): void
    {
        $client = $this->live();
        $seller = $this->sellerWithPickup(5);   // not in GET /pickup-addresses/
        [$so] = $this->order($seller);

        $this->prepare($so, $seller);
        $this->assertRefused($so, 'pickup index 5 does not exist at Intigo', $client);

        // Pickup created at Intigo: "Retry send" checks again (the cached list is re-read on a miss)
        $client->pickups[5] = 'Sfax';
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/retry")->assertOk();
        $this->assertCount(1, $client->calls['create']);
        $this->assertSame(5, $client->calls['create'][0]['pickup_index']);
        $this->assertSame('sent', $this->so($so)->intigo_sync_status);
    }

    public function test_the_intigo_pickup_list_is_cached_between_sends(): void
    {
        $client = $this->live();
        $a = $this->sellerWithPickup(3);
        [$s1] = $this->order($a);
        $this->prepare($s1, $a);
        [$s2] = $this->order($a);
        $this->prepare($s2, $a);

        $this->assertCount(2, $client->calls['create']);
        $this->assertCount(1, $client->calls['pickupAddresses'], 'one GET /pickup-addresses/ for both parcels');
    }

    public function test_an_index_pointing_to_another_governorate_is_refused(): void
    {
        $client = $this->live();
        $client->pickups[3] = 'Tunis';   // our seller's pickup is in Sfax
        $seller = $this->sellerWithPickup(3);
        [$so] = $this->order($seller);

        $this->prepare($so, $seller);
        $this->assertRefused($so, 'pickup index 3 at Intigo is in Tunis', $client);
    }

    public function test_an_index_shared_with_another_seller_is_refused(): void
    {
        $client = $this->scripted();
        $other  = $this->sellerWithPickup(3);
        $seller = $this->sellerWithPickup(3);
        [$so] = $this->order($seller);

        $this->prepare($so, $seller);
        $this->assertRefused($so, "pickup index 3 is also set on seller(s) #{$other->id}", $client);
    }

    public function test_the_platform_index_is_never_used_for_a_seller(): void
    {
        $client = $this->scripted();
        $seller = $this->sellerWithPickup(0);   // = INTIGO_PLATFORM_PICKUP_INDEX in these tests
        [$so] = $this->order($seller);

        $this->prepare($so, $seller);
        $this->assertRefused($so, "pickup index 0 is the platform's own pickup", $client);
    }

    public function test_a_seller_without_pickup_is_held_alerted_and_sent_once_the_pickup_is_created(): void
    {
        $client = $this->scripted();
        $seller = $this->seller();   // verified address, never created in Intigo: no index
        [$so] = $this->order($seller);

        $this->prepare($so, $seller);
        $this->assertRefused($so, 'seller has no Intigo pickup address', $client);
        Mail::assertQueued(IntigoAlertMail::class, fn ($m) => str_contains(json_encode($m), 'Retry send'));

        app(PickupAddressService::class)->markCreatedManually($seller, $this->admin, 7, 'Shop 7');

        $this->assertCount(1, $client->calls['create'], 're-sent automatically');
        $this->assertSame(7, $client->calls['create'][0]['pickup_index']);
        $this->assertSame('sent', $this->so($so)->intigo_sync_status);
    }

    // ── Client address ─────────────────────────────────────────────────────

    public function test_a_phone_with_fewer_than_8_digits_is_refused(): void
    {
        $client = $this->live();
        $seller = $this->sellerWithPickup(3);
        [$so, $orderId] = $this->order($seller);
        DB::table('orders')->where('id', $orderId)->update(['phone' => '2212345']);

        $this->prepare($so, $seller);
        $this->assertRefused($so, 'client phone is not 8 digits', $client);
    }

    public function test_a_governorate_unknown_to_intigo_is_refused(): void
    {
        $client = $this->live();
        $seller = $this->sellerWithPickup(3);
        [$so, $orderId] = $this->order($seller);
        DB::table('orders')->where('id', $orderId)->update(['city_name' => 'Atlantis']);

        $this->prepare($so, $seller);
        $this->assertRefused($so, 'client governorate "Atlantis" is not one of Intigo\'s governorates', $client);
    }

    public function test_live_mode_refuses_when_regions_are_not_synced(): void
    {
        $client = $this->scripted();
        $seller = $this->sellerWithPickup(3);
        [$so] = $this->order($seller);   // free-text address (no regions)
        config(['intigo.mode' => 'live']);

        $this->prepare($so, $seller);
        $this->assertRefused($so, 'Intigo regions not synced', $client);
    }

    // ── The whole path, two sellers ────────────────────────────────────────

    public function test_two_sellers_two_parcels_own_pickup_own_cod_same_checkout_address(): void
    {
        $client = $this->live();
        $client->pickups[4] = 'Sfax';
        $a = $this->sellerWithPickup(3);
        $b = $this->sellerWithPickup(4);
        $pa = $this->product($a, 100);
        $coupon = Coupon::create(['seller_id' => $a->id, 'code' => 'SAFE' . Str::upper(Str::random(5)), 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true]);
        $coupon->products()->attach($pa->id);
        $this->addToCart($pa);
        $this->addToCart($this->product($b, 120, true));   // B offers the delivery

        $quote = $this->quote(['coupon_codes' => [$coupon->code]]);
        $checkout = self::TUNIS_BAB_BHAR + ['coupon_codes' => [$coupon->code], 'phone_secondary' => '+216 98 765 432', 'notes' => 'Près de la poste'];
        [$res, $orderId] = $this->placeAndConfirm($checkout);

        // The client changes their profile and address book after ordering: the parcels keep the checkout address
        $this->customer->forceFill(['phone' => '55000000', 'first_name' => 'Autre'])->save();
        DB::table('user_addresses')->where('user_id', $this->customer->id)->update(['address' => 'Nouvelle adresse 99', 'phone' => '55000000', 'recipient_name' => 'Autre Nom']);

        $parcels = DB::table('seller_orders')->where('order_id', $orderId)->orderBy('id')->get();
        $this->assertCount(2, $parcels, 'one parcel per seller');
        foreach ([$a, $b] as $seller) {
            $this->prepare($this->parcel($orderId, $seller), $seller);
        }
        $this->assertCount(2, $client->calls['create']);

        $sent = collect($client->calls['create'])->keyBy('pickup_index');
        $this->assertSame([3, 4], $sent->keys()->sort()->values()->all(), "each parcel carries its own seller's pickup");
        $this->assertEquals(98.0, $sent[3]['price'], 'A: 100 − 10 coupon + 8 delivery');
        $this->assertEquals(120.0, $sent[4]['price'], 'B: 120, delivery offered by the seller');
        $this->assertEquals($quote['total'], $sent->sum('price'), 'Σ COD = what the client was shown');
        $this->assertEquals((float) $res['total'], $sent->sum('price'));
        $this->assertEquals((float) DB::table('orders')->where('id', $orderId)->value('total_amount'), $sent->sum('price'));

        foreach ($sent as $index => $payload) {
            $so = $this->parcel($orderId, $index === 3 ? $a : $b);
            $this->assertSame('CT-SO-' . $so->id, $payload['cid']);
            $this->assertEquals([
                'recipient_name' => 'Test Buyer', 'phone1' => '22123456', 'phone2' => '98765432',
                'destination_address' => '1 rue Test — Près de la poste', 'city_name' => 'Tunis', 'district_name' => 'Bab Bhar',
            ], array_intersect_key($payload, array_flip(['recipient_name', 'phone1', 'phone2', 'destination_address', 'city_name', 'district_name'])));
            $this->assertSame(1, $payload['package_size']);
            $this->assertFalse($payload['can_open']);
        }

        // The dry run shows exactly what was sent
        Artisan::call('intigo:dry-run', ['--order' => [(string) $orderId], '--json' => true]);
        $report = json_decode(Artisan::output(), true)[0];
        $this->assertTrue($report['check']['sum_cod_equals_order_total']);
        foreach ($report['parcels'] as $p) {
            $this->assertEquals($sent[$p['payload']['pickup_index']], $p['payload'], 'same body (JSON prints 98.0 as 98)');
        }

        // Admin order details: pickup, address sent, COD, nid
        $detail = $this->as($this->admin)->getJson("/api/admin/orders/{$orderId}")->assertOk()->json('data.seller_orders');
        foreach ($detail as $row) {
            $i = $row['intigo'];
            $this->assertSame($row['seller_id'] === $a->id ? 3 : 4, $i['pickup']['index']);
            $this->assertStringContainsString('Route de Tunis km 5', $i['pickup']['address']);
            $this->assertTrue($i['payload_is_sent']);
            $this->assertSame('1 rue Test — Près de la poste', $i['payload']['destination_address']);
            $this->assertEquals($i['cod_amount'], $i['payload']['price']);
            $this->assertNotNull($i['nid']);
        }
    }

    public function test_admin_order_details_preview_what_will_be_sent_and_why_not(): void
    {
        $this->live();
        $seller = $this->sellerWithPickup(3);
        [$so, $orderId] = $this->order($seller);
        DB::table('orders')->where('id', $orderId)->update(['phone' => '123']);

        $i = $this->as($this->admin)->getJson("/api/admin/orders/{$orderId}")->assertOk()->json('data.seller_orders.0.intigo');
        $this->assertFalse($i['payload_is_sent']);
        $this->assertSame(3, $i['pickup']['index']);
        $this->assertSame('created', $i['pickup']['status']);
        $this->assertSame('Bab Bhar', $i['payload']['district_name']);
        $this->assertEquals(48.0, $i['payload']['price']);
        $this->assertStringContainsString('client phone is not 8 digits', implode(' ', $i['payload_problems']));
    }
}
