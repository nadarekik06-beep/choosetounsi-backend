<?php

namespace Tests\Feature\Favorites;

use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST adds (idempotent, never removes), DELETE /favorites/{productId} removes.
 * A storefront whose list is stale can therefore never flip a favorite off by
 * "adding" it, and removing works with the id in the URL.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Favorites
 */
class FavoritesApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $customer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());

        $seller = $this->makeUser('seller');
        $this->customer = $this->makeUser('client');
        $name = 'Fav Product ' . Str::random(6);
        $this->product = Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name), 'price' => 40,
            'stock' => 10, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'Fav ' . Str::random(5), 'email' => "fav_{$role}_" . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true,
        ]);
    }

    private function api(): static
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->customer->createToken('t')->plainTextToken]);
    }

    private function favoriteCount(): int
    {
        return Favorite::where('user_id', $this->customer->id)->where('product_id', $this->product->id)->count();
    }

    public function test_adding_is_idempotent_and_never_removes(): void
    {
        $first = $this->api()->postJson('/api/favorites', ['product_id' => $this->product->id])->assertOk();
        $first->assertJsonPath('favorited', true)->assertJsonPath('data.product_id', $this->product->id);

        // Second add (e.g. a stale heart, a double click, another tab): still one favorite
        $this->api()->postJson('/api/favorites', ['product_id' => $this->product->id])
            ->assertOk()->assertJsonPath('favorited', true)->assertJsonPath('data.product_id', $this->product->id);
        $this->assertSame(1, $this->favoriteCount());

        $this->assertSame([$this->product->id], collect($this->api()->getJson('/api/favorites')->json('data'))->pluck('product_id')->all());
    }

    public function test_removing_uses_the_product_id_from_the_url(): void
    {
        $this->api()->postJson('/api/favorites', ['product_id' => $this->product->id])->assertOk();

        $this->api()->deleteJson("/api/favorites/{$this->product->id}")->assertOk()->assertJsonPath('favorited', false);
        $this->assertSame(0, $this->favoriteCount());

        // Removing again is harmless
        $this->api()->deleteJson("/api/favorites/{$this->product->id}")->assertOk();
    }

    public function test_removing_a_variant_keeps_the_other_favorites_of_the_product(): void
    {
        $red  = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'R-' . Str::random(5), 'stock' => 3, 'is_active' => true]);
        $blue = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'B-' . Str::random(5), 'stock' => 3, 'is_active' => true]);
        $this->api()->postJson('/api/favorites', ['product_id' => $this->product->id, 'variant_id' => $red->id])->assertOk();
        $this->api()->postJson('/api/favorites', ['product_id' => $this->product->id, 'variant_id' => $blue->id])->assertOk();

        $this->api()->deleteJson("/api/favorites/{$this->product->id}", ['variant_id' => $red->id])->assertOk();
        $this->assertSame([$blue->id], Favorite::where('user_id', $this->customer->id)->pluck('variant_id')->all());

        // No variant: every favorite of the product goes (what a product-card heart means)
        $this->api()->deleteJson("/api/favorites/{$this->product->id}")->assertOk();
        $this->assertSame(0, $this->favoriteCount());
    }
}
