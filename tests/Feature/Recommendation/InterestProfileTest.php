<?php

namespace Tests\Feature\Recommendation;

use App\Models\Product;
use App\Models\User;
use App\Models\UserInteraction;
use App\Services\Recommendation\InteractionTracker;
use App\Services\Recommendation\InterestProfileService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Recommendation/InterestProfileTest.php
 */
class InterestProfileTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    private InterestProfileService $profiles;
    private InteractionTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fakeTranslator();
        $this->profiles = app(InterestProfileService::class);
        $this->tracker  = app(InteractionTracker::class);
    }

    private function event(User $user, string $type, ?Product $p, int $daysAgo = 0, array $extra = []): void
    {
        UserInteraction::create(array_merge([
            'user_id'     => $user->id,
            'product_id'  => $p?->id,
            'seller_id'   => $p?->seller_id,
            'category_id' => $p?->category_id,
            'event_type'  => $type,
            'created_at'  => now()->subDays($daysAgo),
        ], $extra));
    }

    private function order(User $user, Product $p, string $status = 'pending', int $daysAgo = 0): int
    {
        $at = now()->subDays($daysAgo);
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $user->id, 'order_number' => 'RECO-' . Str::random(10),
            'total_amount' => $p->price, 'shipping_fee' => 0, 'discount_amount' => 0,
            'status' => $status, 'payment_status' => 'unpaid', 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $p->id, 'quantity' => 1,
            'unit_price' => $p->price, 'price' => $p->price, 'total' => $p->price,
            'discount_amount' => 0, 'commission_percentage' => 0, 'commission_amount' => 0,
            'seller_amount' => $p->price, 'plan_used' => 'free', 'created_at' => $at, 'updated_at' => $at,
        ]);
        return $orderId;
    }

    public function test_purchases_outweigh_views_and_scores_are_normalized(): void
    {
        $user   = $this->makeUser();
        $seller = $this->makeUser('seller');
        $bought = $this->makeProduct($seller, $catA = $this->makeCategory());
        $viewed = $this->makeProduct($seller, $catB = $this->makeCategory());

        $this->event($user, 'purchase', $bought);
        $this->event($user, 'view', $viewed);
        $this->event($user, 'view', $viewed);

        $profile = $this->profiles->rebuild($user->id, null);

        $this->assertEquals(1.0, $profile['categories'][$catA->id]);
        $this->assertEqualsWithDelta(2 / 5, $profile['categories'][$catB->id], 0.01);
        $this->assertFalse($profile['is_cold']);
        $this->assertSame(3, $profile['signal_count']);
    }

    public function test_old_activity_decays_with_a_14_day_half_life(): void
    {
        $user   = $this->makeUser();
        $seller = $this->makeUser('seller');
        $fresh  = $this->makeProduct($seller, $catFresh = $this->makeCategory());
        $old    = $this->makeProduct($seller, $catOld = $this->makeCategory());

        $this->event($user, 'cart_add', $fresh, 0);
        $this->event($user, 'cart_add', $old, 28);    // two half-lives → ¼ weight

        $profile = $this->profiles->rebuild($user->id, null);

        $this->assertEqualsWithDelta(0.25, $profile['categories'][$catOld->id], 0.01);
    }

    public function test_cancelled_order_purchase_is_ignored(): void
    {
        $user   = $this->makeUser();
        $seller = $this->makeUser('seller');
        $kept   = $this->makeProduct($seller, $catKept = $this->makeCategory());
        $gone   = $this->makeProduct($seller, $catGone = $this->makeCategory());

        $this->event($user, 'purchase', $kept, 0, ['order_id' => $this->order($user, $kept)]);
        $this->event($user, 'purchase', $gone, 0, ['order_id' => $this->order($user, $gone, 'cancelled')]);

        $profile = $this->profiles->rebuild($user->id, null);

        $this->assertArrayHasKey($catKept->id, $profile['categories']);
        $this->assertArrayNotHasKey($catGone->id, $profile['categories']);
    }

    public function test_bought_products_are_excluded_except_consumables_after_30_days(): void
    {
        $user    = $this->makeUser();
        $seller  = $this->makeUser('seller');
        $food    = $this->makeCategory();
        config(['recommendations.repurchase.category_slugs' => [$food->slug]]);

        $shirt      = $this->makeProduct($seller, $this->makeCategory());
        $oldHoney   = $this->makeProduct($seller, $food);
        $freshHoney = $this->makeProduct($seller, $food);
        $cancelled  = $this->makeProduct($seller, $this->makeCategory());

        $this->order($user, $shirt, 'delivered', 90);
        $this->order($user, $oldHoney, 'delivered', 45);
        $this->order($user, $freshHoney, 'delivered', 5);
        $this->order($user, $cancelled, 'cancelled', 2);

        $excluded = $this->profiles->purchasedExclusions($user->id);

        $this->assertContains($shirt->id, $excluded);
        $this->assertContains($freshHoney->id, $excluded);
        $this->assertNotContains($oldHoney->id, $excluded);
        $this->assertNotContains($cancelled->id, $excluded);
    }

    public function test_profile_is_cached_until_a_new_signal_arrives(): void
    {
        $user   = $this->makeUser();
        $seller = $this->makeUser('seller');
        $p1 = $this->makeProduct($seller, $cat1 = $this->makeCategory());
        $p2 = $this->makeProduct($seller, $cat2 = $this->makeCategory());

        $this->tracker->record('view', $user->id, null, $p1);
        $this->travel(2)->seconds();
        $first = $this->profiles->forActor($user->id, null);

        // Written behind the tracker's back → cached profile must not change
        $this->event($user, 'purchase', $p2);
        $this->assertSame($first, $this->profiles->forActor($user->id, null));

        // Through the tracker → marked dirty → rebuilt
        $this->travel(2)->seconds();
        $this->tracker->record('cart_add', $user->id, null, $p2);
        $this->travel(2)->seconds();
        $this->assertArrayHasKey($cat2->id, $this->profiles->forActor($user->id, null)['categories']);
    }

    public function test_guest_profile_uses_session_and_follows_user_after_merge(): void
    {
        $user   = $this->makeUser();
        $seller = $this->makeUser('seller');
        $p      = $this->makeProduct($seller, $cat = $this->makeCategory());
        $sid    = $this->guestSession();

        $this->tracker->record('view', null, $sid, $p);
        $this->travel(2)->seconds();
        $this->assertArrayHasKey($cat->id, $this->profiles->forActor(null, $sid)['categories']);
        $this->assertTrue($this->profiles->forActor($user->id, null)['is_cold']);

        $this->tracker->mergeGuestHistory($user->id, $sid);
        $this->travel(2)->seconds();

        $this->assertArrayHasKey($cat->id, $this->profiles->forActor($user->id, null)['categories']);
        $this->assertDatabaseMissing('user_interest_profiles', ['session_id' => $sid]);
    }

    public function test_price_range_brand_and_followed_seller_are_learned(): void
    {
        $user    = $this->makeUser();
        $seller  = $this->makeUser('seller');
        $other   = $this->makeUser('seller');
        $cat     = $this->makeCategory();
        $brandId = DB::table('attributes')->where('slug', 'brand')->value('id')
            ?? DB::table('attributes')->insertGetId(['name' => 'Brand', 'name_ar' => 'Brand', 'name_fr' => 'Marque', 'slug' => 'brand', 'type' => 'text']);

        foreach ([40, 50, 60] as $price) {
            $p = $this->makeProduct($seller, $cat, ['price' => $price]);
            DB::table('product_attribute_values')->insert(['product_id' => $p->id, 'attribute_id' => $brandId, 'value' => ' SONY ']);
            $this->event($user, 'favorite_add', $p);
        }
        DB::table('seller_follows')->insert(['user_id' => $user->id, 'seller_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);

        $profile = $this->profiles->rebuild($user->id, null);

        $this->assertEqualsWithDelta(49.3, $profile['price']['center'], 1.0);
        $this->assertEquals(1.0, $profile['brands']['sony']);
        $this->assertSame([$other->id], $profile['followed_seller_ids']);
        $this->assertArrayHasKey($other->id, $profile['sellers']);

        $cheap = $this->profiles->scoreProduct($profile, ['category_id' => $cat->id, 'seller_id' => $seller->id, 'price' => 50], 'sony');
        $pricy = $this->profiles->scoreProduct($profile, ['category_id' => $cat->id, 'seller_id' => $seller->id, 'price' => 900], 'sony');
        $this->assertGreaterThan($pricy['score'], $cheap['score']);
        $this->assertEqualsWithDelta(1.0, $cheap['parts']['price'], 0.05);
    }
}
