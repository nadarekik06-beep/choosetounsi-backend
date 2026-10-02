<?php

namespace Tests\Feature\Seller;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/seller/subscription exposes is_approved_seller so /become-a-vendor
 * never offers the application form to an approved seller, even one with no
 * seller_applications row.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/SubscriptionStatusRoleTest.php
 */
class SubscriptionStatusRoleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_approved_seller_without_application_is_flagged(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'seller', 'is_approved' => true]));

        $this->getJson('/api/seller/subscription')
            ->assertOk()
            ->assertJsonPath('data.has_application', false)
            ->assertJsonPath('data.is_approved_seller', true);
    }

    public function test_client_and_unapproved_seller_are_not_flagged(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson('/api/seller/subscription')->assertOk()->assertJsonPath('data.is_approved_seller', false);

        Sanctum::actingAs(User::factory()->create(['role' => 'seller', 'is_approved' => false]));
        $this->getJson('/api/seller/subscription')->assertOk()->assertJsonPath('data.is_approved_seller', false);
    }
}
