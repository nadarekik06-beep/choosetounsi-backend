<?php

namespace Tests\Feature\Seller;

use App\Models\SellerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GET /api/seller-landing: public stats + showcase for /become-a-vendor.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/SellerLandingTest.php
 */
class SellerLandingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('platform:seller-landing-stats');
        Cache::forget('platform:seller-showcase:12');
    }

    private function seller(array $user, array $app): User
    {
        $u = User::factory()->create(array_merge(['role' => 'seller', 'is_approved' => true, 'is_active' => true], $user));
        SellerApplication::create(array_merge([
            'user_id' => $u->id, 'full_name' => $u->name, 'phone_number' => '20123456',
            'business_name' => 'Shop '.$u->id, 'business_category' => 'Arts & Crafts',
            'wilaya' => 'Nabeul', 'city' => 'Rue des Potiers 12', 'status' => 'approved',
            'plan' => 'free', 'reviewed_at' => now(),
        ], $app));
        return $u;
    }

    public function test_it_is_public_and_returns_stats_shape(): void
    {
        $this->getJson('/api/seller-landing')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'stats'   => ['sellers', 'orders_delivered', 'wilayas_served', 'average_rating', 'reviews'],
                'sellers',
            ]]);
    }

    public function test_showcase_lists_only_active_approved_sellers_without_street_address(): void
    {
        $shown = $this->seller([], [
            'business_name' => 'Poterie Nabeul',
            'business_description' => 'Céramique faite main depuis 1982. Livraison partout.',
        ]);
        $this->seller(['is_active' => false], ['business_name' => 'Inactive Shop']);
        $this->seller(['is_approved' => false], ['business_name' => 'Pending Shop', 'status' => 'pending']);

        $res = $this->getJson('/api/seller-landing')->assertOk();
        $sellers = collect($res->json('data.sellers'));
        $row = $sellers->firstWhere('id', $shown->id);

        $this->assertNotNull($row);
        $this->assertSame('Poterie Nabeul', $row['business_name']);
        $this->assertSame('Nabeul', $row['wilaya']);
        $this->assertSame('Céramique faite main depuis 1982.', $row['quote']);
        $this->assertArrayNotHasKey('city', $row);
        $this->assertNull($sellers->firstWhere('business_name', 'Inactive Shop'));
        $this->assertNull($sellers->firstWhere('business_name', 'Pending Shop'));
    }
}
