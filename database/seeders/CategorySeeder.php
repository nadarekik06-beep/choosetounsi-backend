<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run()
    {
        $categories = [
            [
                'name' => 'Handicrafts',
                'name_ar' => 'الحرف اليدوية',
                'description' => 'Traditional Tunisian handicrafts and artisan products',
                'icon' => '🎨',
                'order' => 1,
            ],
            [
                'name' => 'Food Products',
                'name_ar' => 'المنتجات الغذائية',
                'description' => 'Local food, spices, olive oil, dates, and more',
                'icon' => '🍯',
                'order' => 2,
            ],
            [
                'name' => 'Textiles & Fashion',
                'name_ar' => 'المنسوجات والأزياء',
                'description' => 'Traditional clothing, fabrics, and accessories',
                'icon' => '👗',
                'order' => 3,
            ],
            [
                'name' => 'Pottery & Ceramics',
                'name_ar' => 'الفخار والسيراميك',
                'description' => 'Handmade pottery and ceramic items',
                'icon' => '🏺',
                'order' => 4,
            ],
            [
                'name' => 'Jewelry & Accessories',
                'name_ar' => 'المجوهرات والإكسسوارات',
                'description' => 'Traditional and modern Tunisian jewelry',
                'icon' => '💎',
                'order' => 5,
            ],
            [
                'name' => 'Home Decor',
                'name_ar' => 'ديكور المنزل',
                'description' => 'Decorative items for your home',
                'icon' => '🏠',
                'order' => 6,
            ],
            [
                'name' => 'Beauty & Wellness',
                'name_ar' => 'الجمال والعافية',
                'description' => 'Natural cosmetics and wellness products',
                'icon' => '🧴',
                'order' => 7,
            ],
            [
                'name' => 'Art & Paintings',
                'name_ar' => 'الفن واللوحات',
                'description' => 'Original artwork by Tunisian artists',
                'icon' => '🖼️',
                'order' => 8,
            ],
        ];

        foreach ($categories as $category) {
            DB::table('categories')->insert([
                'name' => $category['name'],
                'name_ar' => $category['name_ar'],
                'slug' => Str::slug($category['name']),
                'description' => $category['description'],
                'icon' => $category['icon'],
                'order' => $category['order'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}