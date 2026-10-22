<?php

namespace Tests\Feature\Intigo;

use App\Models\Coupon;
use App\Models\IntigoZone;
use App\Models\User;
use App\Services\Intigo\PickupVerifier;
use App\Support\TestAccounts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dry runs with TEST accounts through the real checkout (nothing reaches
 * Intigo: ScriptedIntigoClient, and TEST orders are refused in live mode):
 *   (a) one seller
 *   (b) two sellers, a coupon at A, free delivery offered by B
 * Set INTIGO_DRY_RUN_DIR to keep the `intigo:dry-run` output of each.
 */
class IntigoDryRunScenarioTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private ScriptedIntigoClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        TestAccounts::flush();
        PickupVerifier::forget();
        Mail::fake();
        $this->client = $this->scripted();
        $this->client->pickups = [3 => 'Tunis', 4 => 'Sfax'];
        config(['intigo.mode' => 'live']);
        $this->seedRegions();

        $this->customer->forceFill(['email' => 'choosetounsi+testbuyer-' . Str::lower(Str::random(4)) . '@gmail.com', 'name' => 'TEST Buyer'])->save();
    }

    private function testSeller(string $shop, int $index, string $street, string $delegation, string $gouvernorat, string $phone): User
    {
        $seller = $this->seller();
        $seller->forceFill(['email' => 'choosetounsi+testseller-' . Str::lower(Str::random(4)) . '@gmail.com', 'intigo_pickup_index' => $index, 'intigo_pickup_label' => "{$street} — Tél : +216 {$phone}"])->save();
        $seller->sellerApplication()->update(['business_name' => $shop]);
        $this->givePickup($seller, true, [
            'zone' => $this->pickupZone(IntigoZone::DELEGATION, $delegation, $delegation, $gouvernorat),
            'adresse_complete' => $street, 'phone' => $phone, 'contact_name' => 'TEST ' . $shop,
        ]);
        return $seller->fresh();
    }

    private function checkoutAddress(): array
    {
        return [
            'intigo_city_id' => 23, 'intigo_district_id' => 235,   // Tunis / Bab Bhar
            'recipient_name' => 'TEST Sami Ben Salah', 'phone' => '22 123 456', 'phone_secondary' => '+216 98 765 432',
            'address' => '15 Rue de Marseille, 2e étage', 'postal_code' => '1000', 'notes' => 'Face à la pharmacie',
        ];
    }

    private function dryRun(int $orderId, string $name): array
    {
        Artisan::call('intigo:dry-run', ['--order' => [(string) $orderId], '--json' => true]);
        $json = Artisan::output();
        Artisan::call('intigo:dry-run', ['--order' => [(string) $orderId]]);
        $text = Artisan::output();
        if ($dir = getenv('INTIGO_DRY_RUN_DIR')) {
            @mkdir($dir, 0775, true);
            file_put_contents("{$dir}/{$name}.json", $json);
            file_put_contents("{$dir}/{$name}.txt", $text);
        }
        return json_decode($json, true)[0];
    }

    private function place(array $coupons = []): int
    {
        $quote = $this->quote(['coupon_codes' => $coupons]);
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->checkoutAddress() + [
            'coupon_codes' => $coupons, 'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ])->assertCreated()->json();
        $this->assertEquals($quote['total'], $res['total']);
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/confirm-order", ['action' => 'confirmed'])->assertOk();
        return (int) $res['order_id'];
    }

    public function test_a_single_seller_order(): void
    {
        $a = $this->testSeller('TEST Chéchias de la Médina', 3, '12 Souk Echaouachia', 'Bab Bhar', 'Tunis', '57252576');
        $this->addToCart(tap($this->product($a, 35))->update(['name' => 'TEST Chéchia rouge traditionnelle']), 2);
        $orderId = $this->place();

        $r = $this->dryRun($orderId, 'a-single-seller');
        $this->assertCount(1, $r['parcels']);
        $p = $r['parcels'][0];
        $this->assertSame(3, $p['payload']['pickup_index']);
        $this->assertEquals(78.0, $p['payload']['price'], '2 × 35 + 8 delivery');
        $this->assertSame(['22123456', '98765432'], [$p['payload']['phone1'], $p['payload']['phone2']]);
        $this->assertSame(['Tunis', 'Bab Bhar'], [$p['payload']['city_name'], $p['payload']['district_name']]);
        $this->assertSame('15 Rue de Marseille, 2e étage — Face à la pharmacie', $p['payload']['destination_address']);
        $this->assertTrue($r['check']['sum_cod_equals_order_total']);
        $this->assertOnlyTestAccountProblems($p['problems']);

        // A TEST seller clicking "prepared" in live mode: refused, nothing created at Intigo
        $this->prepare($this->parcel($orderId, $a), $a);
        $this->assertCount(0, $this->client->calls['create']);
    }

    public function test_two_sellers_with_a_coupon_and_free_delivery_from_one(): void
    {
        $a = $this->testSeller('TEST Chéchias de la Médina', 3, '12 Souk Echaouachia', 'Bab Bhar', 'Tunis', '57252576');
        $b = $this->testSeller('TEST Huilerie de Sfax', 4, 'Route de Gremda km 4', 'Sfax Ville', 'Sfax', '28522353');
        $chechia = tap($this->product($a, 35))->update(['name' => 'TEST Chéchia rouge traditionnelle']);
        $coupon = Coupon::create(['seller_id' => $a->id, 'code' => 'TESTMEDINA' . Str::upper(Str::random(3)), 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);
        $coupon->products()->attach($chechia->id);
        $this->addToCart($chechia, 2);
        $this->addToCart(tap($this->product($b, 28, true))->update(['name' => "TEST Huile d'olive extra vierge 1 L"]), 3);   // olive oil, delivery offered by B
        $orderId = $this->place([$coupon->code]);

        $r = $this->dryRun($orderId, 'b-two-sellers-coupon-free-delivery');
        $this->assertCount(2, $r['parcels'], 'one parcel per seller');
        $by = collect($r['parcels'])->keyBy(fn ($p) => $p['seller']['id']);
        $this->assertSame(3, $by[$a->id]['payload']['pickup_index']);
        $this->assertSame(4, $by[$b->id]['payload']['pickup_index']);
        $this->assertEquals(71.0, $by[$a->id]['payload']['price'], '70 − 7 (10 %) + 8 delivery');
        $this->assertEquals(84.0, $by[$b->id]['payload']['price'], '3 × 28, delivery offered');
        $this->assertEquals(155.0, $r['check']['sum_cod']);
        $this->assertTrue($r['check']['sum_cod_equals_order_total']);
        $this->assertEquals(155.0, (float) DB::table('orders')->where('id', $orderId)->value('total_amount'));
        foreach ($r['parcels'] as $p) {
            $this->assertSame('15 Rue de Marseille, 2e étage — Face à la pharmacie', $p['payload']['destination_address'], 'same client address on both');
            $this->assertOnlyTestAccountProblems($p['problems']);
        }

        foreach ([$a, $b] as $seller) {
            $this->prepare($this->parcel($orderId, $seller), $seller);
        }
        $this->assertCount(0, $this->client->calls['create'], 'TEST orders never reach Intigo');
        $this->assertSame(0, DB::table('intigo_logs')->where('method', 'POST')->count());
    }

    /** The payload itself is valid: only the TEST-account rule stops it. */
    private function assertOnlyTestAccountProblems(array $problems): void
    {
        $this->assertNotEmpty($problems);
        foreach ($problems as $problem) {
            $this->assertStringContainsString('TEST', $problem);
        }
    }
}
