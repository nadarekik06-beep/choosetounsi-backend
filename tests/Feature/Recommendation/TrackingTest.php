<?php

namespace Tests\Feature\Recommendation;

use App\Models\UserInteraction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Signal tracking for homepage personalization.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Recommendation/TrackingTest.php
 */
class TrackingTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_guest_view_and_click_are_recorded_with_session_and_section(): void
    {
        $product = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        $sid = $this->guestSession();

        $this->withHeaders(['X-Session-Id' => $sid])->postJson('/api/track', ['events' => [
            ['type' => 'view',  'product_id' => $product->id],
            ['type' => 'click', 'product_id' => $product->id, 'source_section' => 'trending'],
        ]])->assertStatus(202)->assertJson(['recorded' => 2]);

        $rows = UserInteraction::where('session_id', $sid)->orderBy('id')->get();
        $this->assertSame(['view', 'click'], $rows->pluck('event_type')->all());
        $this->assertNull($rows[0]->user_id);
        $this->assertSame($product->seller_id, $rows[0]->seller_id);
        $this->assertSame($product->category_id, $rows[0]->category_id);
        $this->assertSame('trending', $rows[1]->source_section);
    }

    public function test_bearer_token_user_is_recognised_on_the_public_route(): void
    {
        $user    = $this->makeUser();
        $product = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        $token   = $user->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/track', ['events' => [['type' => 'view', 'product_id' => $product->id]]])
            ->assertStatus(202);

        $this->assertDatabaseHas('user_interactions', ['user_id' => $user->id, 'product_id' => $product->id, 'event_type' => 'view']);
    }

    public function test_repeated_views_are_debounced(): void
    {
        $product = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        $sid = $this->guestSession();

        foreach (range(1, 10) as $_) {
            $this->withHeaders(['X-Session-Id' => $sid])
                ->postJson('/api/track', ['events' => [['type' => 'view', 'product_id' => $product->id]]]);
        }

        $this->assertSame(1, UserInteraction::where('session_id', $sid)->where('event_type', 'view')->count());
    }

    public function test_client_cannot_forge_server_side_events(): void
    {
        $product = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());

        $this->withHeaders(['X-Session-Id' => $this->guestSession()])
            ->postJson('/api/track', ['events' => [['type' => 'purchase', 'product_id' => $product->id]]])
            ->assertStatus(422);
    }

    public function test_events_without_any_identity_are_ignored(): void
    {
        $product = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());

        $this->postJson('/api/track', ['events' => [['type' => 'view', 'product_id' => $product->id]]])
            ->assertStatus(202)->assertJson(['recorded' => 0]);
    }

    public function test_guest_history_is_merged_on_login(): void
    {
        $user    = $this->makeUser();
        $product = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        $sid     = $this->guestSession();

        $this->withHeaders(['X-Session-Id' => $sid])
            ->postJson('/api/track', ['events' => [['type' => 'view', 'product_id' => $product->id]]]);

        $token = $user->createToken('test')->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer $token", 'X-Session-Id' => $sid])
            ->postJson('/api/track/merge')
            ->assertOk()->assertJson(['merged' => 1]);

        $this->assertDatabaseHas('user_interactions', ['session_id' => $sid, 'user_id' => $user->id]);
    }

    public function test_favourite_toggle_and_follow_are_tracked_server_side(): void
    {
        $user    = $this->makeUser();
        $seller  = $this->makeUser('seller');
        $product = $this->makeProduct($seller, $this->makeCategory());
        $token   = $user->createToken('test')->plainTextToken;
        $auth    = ['Authorization' => "Bearer $token"];

        $this->withHeaders($auth)->postJson('/api/favorites', ['product_id' => $product->id])->assertOk();
        $this->withHeaders($auth)->postJson('/api/favorites', ['product_id' => $product->id])->assertOk();
        $this->withHeaders($auth)->postJson("/api/seller-follows/{$seller->id}")->assertOk();

        $events = UserInteraction::where('user_id', $user->id)->orderBy('id')->pluck('event_type')->all();
        $this->assertSame(['favorite_add', 'favorite_remove', 'follow'], $events);
        $this->assertDatabaseHas('user_interactions', ['user_id' => $user->id, 'event_type' => 'follow', 'seller_id' => $seller->id]);
    }

    public function test_product_page_view_counter_is_debounced_per_visitor(): void
    {
        $product = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        $sid = $this->guestSession();

        $this->withHeaders(['X-Session-Id' => $sid])->getJson("/api/products/{$product->slug}")->assertOk();
        $this->withHeaders(['X-Session-Id' => $sid])->getJson("/api/products/{$product->slug}")->assertOk();

        $this->assertSame(1, $product->fresh()->views);
    }
}
