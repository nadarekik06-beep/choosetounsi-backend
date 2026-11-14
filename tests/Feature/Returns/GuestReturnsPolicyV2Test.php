<?php

namespace Tests\Feature\Returns;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Orders\CheckoutGuard;
use App\Services\Orders\GuestOrderAccess;
use App\Services\Returns\ReturnPolicy;
use App\Support\GuestCheckout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;
use Tests\TestCase;

/**
 * Guest clients (no account, X-Order-Token) under the exchange-first policy: same rules,
 * 2-3 photos, the courier pays them in cash at pick-up (guests never had a wallet), and the
 * tracking endpoints show the new fields.
 */
class GuestReturnsPolicyV2Test extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private User $shop;
    private string $cartToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        config(['guest_checkout.enabled' => true]);
        PlatformSetting::where('key', GuestCheckout::SETTING_KEY)->delete();
        PlatformSetting::flushCache();
        Cache::flush();
        Storage::fake('public');
        $this->shop = $this->seller();
        app(ReturnPolicy::class)->update(['v2_enabled' => true], $this->admin->id);
    }

    private function anon()
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        return $this;
    }

    private function guestOrder(): array
    {
        $this->cartToken = Str::random(40);
        $this->anon()->withHeaders([GuestCheckout::CART_HEADER => $this->cartToken])
            ->postJson('/api/cart', ['product_id' => $this->product($this->shop, 50)->id, 'quantity' => 1])->assertOk();
        $this->travel(CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $quote = $this->anon()->withHeaders([GuestCheckout::CART_HEADER => $this->cartToken])
            ->postJson('/api/checkout/quote', ['phone' => '57252576'])->assertOk()->json('data');
        $res = $this->anon()->withHeaders([GuestCheckout::CART_HEADER => $this->cartToken])->postJson('/api/checkout', [
            'recipient_name' => 'Amel Ben Ali', 'wilaya' => 'Tunis', 'delegation' => 'Bab Bhar',
            'address' => '5 rue de la Kasbah', 'postal_code' => '1000', 'phone' => '57252576',
            'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ])->assertCreated()->json();
        $order = Order::findOrFail($res['order_id']);
        DB::table('seller_orders')->where('order_id', $order->id)->update(['status' => 'delivered', 'delivery_confirmed_at' => now()]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered', 'updated_at' => now()]);
        return [$order->fresh(), $res['tracking_token']];
    }

    private function token(string $token)
    {
        return $this->anon()->withHeaders([GuestOrderAccess::HEADER => $token]);
    }

    private function payload(int $photos): array
    {
        return [
            'complaint_type' => 'changed_mind', 'item_unused_confirmed' => 1, 'return_all' => 1,
            'description' => 'Je change d\'avis, article neuf avec étiquettes.',
            'images' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 400, 300), range(1, $photos)),
        ];
    }

    public function test_guest_needs_two_to_three_photos_and_is_paid_cash_at_pickup_with_the_frozen_amount(): void
    {
        [$order, $token] = $this->guestOrder();

        $eligible = $this->token($token)->getJson('/api/guest/complaints/eligible-orders')->assertOk();
        $this->assertTrue($eligible->json('policy.v2'));
        $this->assertSame(2, $eligible->json('policy.min_photos'));

        $this->token($token)->post('/api/guest/complaints', $this->payload(1), ['Accept' => 'application/json'])->assertStatus(422);
        $id = $this->token($token)->post('/api/guest/complaints', $this->payload(2), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $c = Complaint::findOrFail($id);
        $this->assertNull($c->user_id, 'a guest return');
        $this->assertSame(2, $c->policy_version);

        // Seller accepts the photos, admin approves: the amount is frozen before any pick-up exists
        $this->as($this->shop)->patchJson("/api/seller/complaints/{$c->id}/approve", ['seller_note' => 'ok'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve")->assertOk();
        $c->refresh();
        $this->assertNotNull($c->refund_amount_locked);

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Intigo'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
        $c->refresh();

        $this->assertSame('cash', $c->refund_method, 'no wallet for a guest: the courier pays cash');
        $this->assertEquals((float) $c->refund_amount_locked, (float) $c->refund_amount);
        $this->assertTrue($c->payoutIsFrozen());

        // The guest follows it on the tracking endpoint (allow-listed fields only)
        $show = $this->token($token)->getJson("/api/guest/complaints/{$c->id}")->assertOk()->json('data');
        $this->assertSame(2, $show['policy_version']);
        $this->assertArrayNotHasKey('inspection_comment', $show);
        $this->assertArrayNotHasKey('refund_amount_locked', $show);
        $this->assertNull($show['sent_back']);
    }
}
