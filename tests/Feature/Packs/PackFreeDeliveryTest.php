<?php

namespace Tests\Feature\Packs;

use App\Models\Pack;
use App\Models\PackItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The deal page shows "Free delivery" only when the pack really ships free:
 * every product in it offers free delivery (same rule as OrderPricing).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Packs/PackFreeDeliveryTest.php
 */
class PackFreeDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    private function product(User $seller, ?float $deliveryFee): Product
    {
        $name = 'Pack Product ' . Str::random(6);
        return Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => 20, 'stock' => 10, 'is_approved' => true, 'is_active' => true,
            'delivery_fee' => $deliveryFee,
        ]);
    }

    private function pack(array $fees): Pack
    {
        $seller = User::create([
            'name' => 'Seller ' . Str::random(5), 'email' => 'pack_' . Str::random(10) . '@test.local',
            'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
        ]);
        $name = 'Pack ' . Str::random(6);
        $pack = Pack::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'pack_price' => 30, 'original_price' => 40, 'is_active' => true, 'is_approved' => true,
        ]);
        foreach ($fees as $i => $fee) {
            PackItem::create(['pack_id' => $pack->id, 'product_id' => $this->product($seller, $fee)->id, 'quantity' => 1, 'order' => $i]);
        }
        return $pack;
    }

    public function test_pack_is_free_delivery_only_when_all_products_are(): void
    {
        $this->getJson('/api/packs/' . $this->pack([0, 0])->slug)
            ->assertOk()->assertJsonPath('data.is_free_delivery', true);

        $this->getJson('/api/packs/' . $this->pack([0, null])->slug)
            ->assertOk()->assertJsonPath('data.is_free_delivery', false);

        $this->getJson('/api/packs/' . $this->pack([null])->slug)
            ->assertOk()->assertJsonPath('data.is_free_delivery', false);
    }
}
