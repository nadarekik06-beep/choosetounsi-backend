<?php

namespace Tests\Feature\Recommendation;

use App\Services\UserPreferenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * GET /api/products is public, but a logged-in shopper (Bearer token) must get
 * the personalized ranking.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Recommendation/ProductListingAuthTest.php
 */
class ProductListingAuthTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTranslator();
    }

    public function test_bearer_token_user_gets_the_personalized_listing(): void
    {
        $user = $this->makeUser();
        $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        $spy = $this->spy(UserPreferenceService::class);
        $spy->shouldReceive('getCombinedPreferences')->andReturn(null);
        $spy->shouldReceive('getActivityWeights')->andReturn([]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken])
            ->getJson('/api/products')
            ->assertOk();

        $spy->shouldHaveReceived('getCombinedPreferences')->with($user->id);
    }

    public function test_guest_listing_is_not_personalized(): void
    {
        $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        $spy = $this->spy(UserPreferenceService::class);

        $this->getJson('/api/products')->assertOk();

        $spy->shouldNotHaveReceived('getCombinedPreferences');
    }
}
