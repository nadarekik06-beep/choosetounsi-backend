<?php

namespace Tests\Feature\Seller;

use App\Models\IntigoZone;
use App\Models\SellerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;
use Tests\TestCase;

/**
 * Seller access follows the database, not the token: a token issued while the
 * user was a buyer opens the seller API right after the admin approves the
 * application, and closes it as soon as the admin removes the role or suspends
 * the seller. No logout / login in between.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/SellerAccessWithoutReloginTest.php
 */
class SellerAccessWithoutReloginTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private IntigoZone $zone;
    private User $tokenUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        $this->zone = $this->pickupZone(IntigoZone::SECTEUR, 'Relogin Testville', 'Relogin Deleg', 'Ariana',
            ['latitude' => 36.8736, 'longitude' => 10.2379]);
    }

    /** A request with a real bearer token (no actingAs), the guard re-resolved like a new HTTP request. */
    private function bearer(string $token, string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $data);
    }

    private function apply(string $token): SellerApplication
    {
        $this->bearer($token, 'POST', '/api/seller-applications', [
            'full_name' => 'Relogin Test', 'phone_number' => '22 123 456', 'business_name' => 'Relogin ' . Str::random(5),
            'business_category' => 'crafts', 'contact_name' => 'Relogin Test', 'phone' => '22123456',
            'adresse_complete' => '1 Rue du Test', 'intigo_zone_id' => $this->zone->id, 'code_postal' => '2035',
            'latitude' => 36.8741, 'longitude' => 10.2391,
        ])->assertCreated();

        return SellerApplication::where('user_id', $this->tokenUser->id)->firstOrFail();
    }


    public function test_token_from_before_approval_opens_seller_api_and_loses_it_after_role_removal(): void
    {
        $this->tokenUser = $this->makeUser('client');
        $token = $this->tokenUser->createToken('api-token')->plainTextToken;   // as at login

        // Buyer: /auth/user says client, seller API refused, become-a-vendor status still readable
        $this->bearer($token, 'GET', '/api/auth/user')->assertOk()
            ->assertJsonPath('user.role', 'client')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->bearer($token, 'GET', '/api/seller/dashboard')->assertForbidden();
        $this->bearer($token, 'GET', '/api/seller/subscription')->assertOk()
            ->assertJsonPath('data.is_approved_seller', false);

        $app = $this->apply($token);
        $this->as($this->admin)->postJson("/api/admin/seller-applications/{$app->id}/approve")->assertOk();

        // Same token, no new login: seller everywhere
        $this->bearer($token, 'GET', '/api/auth/user')->assertOk()
            ->assertJsonPath('user.role', 'seller')
            ->assertJsonPath('user.is_approved', true);
        $this->bearer($token, 'GET', '/api/seller/dashboard')->assertOk();
        $this->bearer($token, 'GET', '/api/seller/store-profile')->assertOk();
        $this->bearer($token, 'GET', '/api/seller/products/stats')->assertOk();
        $this->bearer($token, 'GET', '/api/seller/subscription')->assertOk()
            ->assertJsonPath('data.is_approved_seller', true);

        // The approval shows in the storefront bell, its link opens /seller
        $bell = $this->bearer($token, 'GET', '/api/notifications?audience=buyer')->assertOk()->json('data');
        $row = collect($bell)->first(fn ($n) => ($n['data']['type'] ?? $n['type'] ?? null) === 'seller_application_reviewed');
        $this->assertNotNull($row, 'approval notification in the storefront bell');
        $this->assertSame('/seller', $row['data']['link'] ?? $row['link'] ?? null);

        // Admin takes the role back: the same token loses seller access at once
        $this->as($this->admin)->patchJson("/api/admin/sellers/{$this->tokenUser->id}/role", ['role' => 'client'])->assertOk();
        $this->bearer($token, 'GET', '/api/auth/user')->assertOk()->assertJsonPath('user.role', 'client');
        $this->bearer($token, 'GET', '/api/seller/dashboard')->assertForbidden();
        $this->bearer($token, 'GET', '/api/seller/products')->assertForbidden();

        // ...and gets it back the same way
        $this->as($this->admin)->patchJson("/api/admin/sellers/{$this->tokenUser->id}/role", ['role' => 'seller'])->assertOk();
        $this->bearer($token, 'GET', '/api/seller/dashboard')->assertOk();
    }

    public function test_suspended_seller_loses_seller_api_with_the_same_token(): void
    {
        $seller = $this->makeUser('seller');
        $seller->update(['is_approved' => true, 'is_active' => true]);
        $token = $seller->createToken('api-token')->plainTextToken;

        $this->bearer($token, 'GET', '/api/seller/dashboard')->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/sellers/{$seller->id}/suspend")->assertOk();
        $this->bearer($token, 'GET', '/api/seller/dashboard')->assertForbidden();
    }
}
