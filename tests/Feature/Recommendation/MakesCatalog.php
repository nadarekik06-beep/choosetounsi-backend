<?php

namespace Tests\Feature\Recommendation;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

/** Throwaway users/categories/products for recommendation tests (never reuse existing rows). */
trait MakesCatalog
{
    /** Saving a product queues a Groq translation after the response — keep tests offline and fast. */
    protected function fakeTranslator(): void
    {
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());
    }

    protected function makeUser(string $role = 'client', array $attrs = []): User
    {
        return User::query()->forceCreate(array_merge([
            'name'        => 'Reco ' . Str::random(5),
            'email'       => 'reco_' . Str::random(12) . '@test.local',
            'password'    => bcrypt('secret-password'),
            'role'        => $role,
            'is_active'   => true,
            'is_approved' => true,
        ], $attrs));
    }

    protected function makeCategory(string $slug = null): Category
    {
        $s = Str::random(6);
        return Category::create([
            'name' => "Cat $s", 'name_ar' => "Cat $s", 'name_fr' => "Cat $s",
            'slug' => $slug ?? "reco-cat-$s", 'is_active' => true,
        ]);
    }

    protected function makeProduct(User $seller, Category $category, array $attrs = []): Product
    {
        $s = Str::random(8);
        return Product::create(array_merge([
            'seller_id'   => $seller->id,
            'category_id' => $category->id,
            'name'        => "Reco product $s",
            'slug'        => "reco-product-$s",
            'description' => 'Throwaway test product',
            'price'       => 50,
            'stock'       => 10,
            'is_approved' => true,
            'is_active'   => true,
        ], $attrs));
    }

    protected function guestSession(): string
    {
        return (string) Str::uuid();
    }
}
