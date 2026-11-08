<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Category / subcategory names follow Accept-Language (seller "Add product" form, storefront).
 * Fallback: locale column → French → English, never empty.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/CategoryNamesLocaleTest.php
 */
class CategoryNamesLocaleTest extends TestCase
{
    use DatabaseTransactions;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Locale Test Cat', 'name_fr' => 'Catégorie test', 'name_ar' => 'فئة تجريبية',
            'slug' => 'locale-test-' . Str::random(6), 'is_active' => true,
        ]);
        Subcategory::create([
            'category_id' => $this->category->id, 'name' => 'Skirts', 'name_fr' => 'Jupes', 'name_ar' => 'تنانير',
            'slug' => 'skirts-' . Str::random(6), 'is_active' => true, 'order' => 1,
        ]);
        // No Arabic name: falls back to French
        Subcategory::create([
            'category_id' => $this->category->id, 'name' => 'Dresses', 'name_fr' => 'Robes', 'name_ar' => null,
            'slug' => 'dresses-' . Str::random(6), 'is_active' => true, 'order' => 2,
        ]);
        // No translation at all: English, never empty
        Subcategory::create([
            'category_id' => $this->category->id, 'name' => 'Belts', 'name_fr' => '', 'name_ar' => null,
            'slug' => 'belts-' . Str::random(6), 'is_active' => true, 'order' => 3,
        ]);
    }

    private function subNames(string $locale): array
    {
        $headers = ['Accept' => 'application/json', 'Accept-Language' => $locale];

        return collect($this->getJson("/api/categories/{$this->category->slug}/subcategories", $headers)
            ->assertOk()->json('data'))->pluck('name')->all();
    }

    public function test_subcategory_names_follow_the_interface_language(): void
    {
        $this->assertSame(['Jupes', 'Robes', 'Belts'], $this->subNames('fr'));
        $this->assertSame(['تنانير', 'Robes', 'Belts'], $this->subNames('ar'));
        $this->assertSame(['Skirts', 'Dresses', 'Belts'], $this->subNames('en'));
    }

    public function test_category_names_follow_the_interface_language(): void
    {
        foreach (['fr' => 'Catégorie test', 'ar' => 'فئة تجريبية', 'en' => 'Locale Test Cat'] as $locale => $expected) {
            $names = collect($this->getJson('/api/categories', ['Accept' => 'application/json', 'Accept-Language' => $locale])
                ->assertOk()->json('data'))->pluck('name', 'id');
            $this->assertSame($expected, $names[$this->category->id], $locale);
        }
    }
}
