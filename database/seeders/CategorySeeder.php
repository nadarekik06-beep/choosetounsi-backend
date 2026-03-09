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
            ['name' => 'Fashion & Clothing', 'name_ar' => 'الأزياء والملابس', 'icon' => '👗'],
            ['name' => 'Electronics & Tech', 'name_ar' => 'الإلكترونيات والتكنولوجيا', 'icon' => '💻'],
            ['name' => 'Home & Living', 'name_ar' => 'المنزل والمعيشة', 'icon' => '🏠'],
            ['name' => 'Food & Grocery', 'name_ar' => 'الطعام والبقالة', 'icon' => '🍎'],
            ['name' => 'Beauty & Personal Care', 'name_ar' => 'الجمال والعناية الشخصية', 'icon' => '💄'],
            ['name' => 'Health & Wellness', 'name_ar' => 'الصحة والعافية', 'icon' => '💊'],
            ['name' => 'Sports & Outdoors', 'name_ar' => 'الرياضة والأنشطة الخارجية', 'icon' => '⚽'],
            ['name' => 'Arts & Crafts', 'name_ar' => 'الفنون والحرف', 'icon' => '🎨'],
            ['name' => 'Books & Stationery', 'name_ar' => 'الكتب والقرطاسية', 'icon' => '📚'],
            ['name' => 'Kids & Baby', 'name_ar' => 'الأطفال والرضع', 'icon' => '🧸'],
            ['name' => 'Automotive', 'name_ar' => 'السيارات', 'icon' => '🚗'],
            ['name' => 'Other', 'name_ar' => 'أخرى', 'icon' => '📦'],
        ];

        foreach ($categories as $index => $category) {
            DB::table('categories')->insert([
                'name' => $category['name'],
                'name_ar' => $category['name_ar'],
                'slug' => Str::slug($category['name']),
                'icon' => $category['icon'],
                'order' => $index + 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}