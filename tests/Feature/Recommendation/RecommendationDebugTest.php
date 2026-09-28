<?php

namespace Tests\Feature\Recommendation;

use App\Models\UserInteraction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Recommendation/RecommendationDebugTest.php
 */
class RecommendationDebugTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fakeTranslator();
        Http::fake(['*/similar' => Http::response(['results' => [], 'seeds_used' => []])]);
    }

    public function test_admin_sees_profile_and_a_reason_for_every_product(): void
    {
        $admin = $this->makeUser('admin');
        $user  = $this->makeUser();
        $cat   = $this->makeCategory();
        $products = array_map(fn ($i) => $this->makeProduct($this->makeUser('seller'), $cat), range(1, 14));
        UserInteraction::create(['user_id' => $user->id, 'product_id' => $products[0]->id, 'seller_id' => $products[0]->seller_id,
            'category_id' => $cat->id, 'event_type' => 'cart_add']);

        $json = $this->withHeaders(['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken])
            ->getJson("/api/admin/recommendations/debug?user_id={$user->id}&refresh=1")
            ->assertOk()
            ->json();

        $this->assertSame('warm', $json['profile']['state']);
        $this->assertSame($cat->name, $json['profile']['categories'][0]['name']);
        $this->assertSame('cart_add', $json['recent_signals'][0]['event_type']);
        $this->assertNotEmpty($json['sections']);
        foreach ($json['sections'] as $section) {
            foreach ($section['products'] as $p) {
                $this->assertNotSame('n/a', $p['reason'], "no reason for #{$p['id']} in {$section['key']}");
            }
        }
    }

    public function test_non_admin_is_refused(): void
    {
        $client = $this->makeUser();

        $this->withHeaders(['Authorization' => 'Bearer ' . $client->createToken('t')->plainTextToken])
            ->getJson("/api/admin/recommendations/debug?user_id={$client->id}")
            ->assertForbidden();
    }
}
