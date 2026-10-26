<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The mega-menu becomes admin-managed for every category, not only gendered ones.
 * Non-gendered categories use the same column tables with gender = 'all'.
 *
 * Seeded once per category from the storefront's former hardcoded MEGA list
 * (Navbar.tsx), with the FR/EN/AR labels from messages/*.json, so menus look the
 * same on day one. Skips a category that already has 'all' columns and any slug
 * that does not exist in that category. Data only: no schema change.
 */
return new class extends Migration
{
    // [key, en, fr, ar, [subcategory slugs]]
    private array $menus = [
        'electronics-tech' => [
            ['phones', 'Phones', 'Téléphones', 'هواتف', ['smartphone', 'phone-case', 'charger']],
            ['computing', 'Computing', 'Informatique', 'إعلامية', ['laptop', 'tablet', 'usb-drive']],
            ['audioVideo', 'Audio & Video', 'Audio & vidéo', 'صوت وصورة', ['earphones', 'bluetooth-speaker', 'headphones', 'tv']],
            ['smartwatches', 'Smartwatches', 'Montres connectées', 'ساعات ذكية', ['smartwatch']],
            ['videoGames', 'Video Games', 'Jeux vidéo', 'ألعاب فيديو', ['gaming-console']],
        ],
        'home-living' => [
            ['furniture', 'Furniture', 'Meubles', 'أثاث', ['sofa', 'bed-frame', 'dining-table']],
            ['decoration', 'Decoration', 'Décoration', 'ديكور', ['candle', 'rug', 'wall-art']],
            ['kitchen', 'Kitchen & Dining', 'Cuisine & art de la table', 'المطبخ والمائدة', ['crockery-set', 'storage-box']],
            ['bedding', 'Bedding', 'Linge de maison', 'مفروشات', ['bed-sheets', 'curtains']],
        ],
        'food-grocery' => [
            ['grocery', 'Grocery', 'Épicerie', 'بقالة', ['olive-oil', 'dates', 'honey', 'canned-goods', 'harissa']],
            ['beverages', 'Beverages', 'Boissons', 'مشروبات', ['tea', 'coffee']],
            ['organic', 'Organic & Natural', 'Bio & naturel', 'منتجات عضوية وطبيعية', ['spices', 'organic-products']],
        ],
        'beauty-personal-care' => [
            ['faceCare', 'Face Care', 'Soins du visage', 'العناية بالوجه', ['moisturiser', 'serum', 'face-mask']],
            ['makeup', 'Makeup', 'Maquillage', 'مكياج', ['foundation', 'lipstick', 'mascara']],
            ['perfumes', 'Perfumes', 'Parfums', 'عطور', ['eau-de-parfum']],
            ['hairCare', 'Hair Care', 'Soins des cheveux', 'العناية بالشعر', ['shampoo', 'hair-mask', 'argan-oil']],
        ],
        'sports-outdoors' => [
            ['sportswear', 'Sportswear', 'Vêtements de sport', 'ملابس رياضية', ['sports-t-shirt', 'tracksuit']],
            ['sportsShoes', 'Sports Shoes', 'Chaussures de sport', 'أحذية رياضية', ['running-shoes', 'football-kit']],
            ['equipment', 'Equipment', 'Équipement', 'معدّات', ['yoga-mat', 'weights', 'bicycle', 'swimming-gear']],
        ],
        'arts-crafts' => [
            ['painting', 'Painting', 'Peinture', 'رسم', ['acrylic-paint', 'canvas']],
            ['crafts', 'Crafts', 'Artisanat', 'حرف يدوية', ['pottery', 'handmade-jewelry', 'embroidery-kit']],
            ['hobbies', 'Creative Hobbies', 'Loisirs créatifs', 'هوايات إبداعية', ['knitting-yarn']],
        ],
        'books-stationery' => [
            ['books', 'Books', 'Livres', 'كتب', ['novel', 'comic-manga', 'school-textbook']],
            ['stationery', 'Stationery', 'Papeterie', 'قرطاسية', ['notebook', 'pen-set', 'planner']],
        ],
        'kids-baby' => [
            ['toys', 'Toys', 'Jouets', 'ألعاب', ['plush-toy', 'educational-game', 'toy-car']],
            ['baby', 'Baby', 'Bébé', 'رضّع', ['baby-clothes', 'stroller', 'baby-bottle']],
        ],
        'automotive' => [
            ['carAccessories', 'Accessories', 'Accessoires auto', 'إكسسوارات السيارات', ['car-accessory', 'car-seat-cover', 'car-perfume']],
            ['motorcycle', 'Motorcycle', 'Moto', 'دراجات نارية', ['motorcycle-gear']],
        ],
        'health-wellness' => [
            ['nutrition', 'Nutrition', 'Nutrition', 'تغذية', ['vitamins', 'protein-powder']],
            ['wellness', 'Wellness', 'Bien-être', 'عافية', ['essential-oil', 'herbal-tea', 'medical-device']],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->menus as $catSlug => $groups) {
            $catId = DB::table('categories')->where('slug', $catSlug)->value('id');
            if (!$catId) continue;
            if (DB::table('gender_menu_groups')->where(['category_id' => $catId, 'gender' => 'all'])->exists()) continue;

            foreach ($groups as $gi => [$key, $en, $fr, $ar, $slugs]) {
                $subIds = DB::table('subcategories')->where('category_id', $catId)->whereIn('slug', $slugs)
                    ->pluck('id', 'slug');
                $ordered = array_values(array_filter(array_map(fn ($s) => $subIds[$s] ?? null, $slugs)));
                if (!$ordered) continue;

                $groupId = DB::table('gender_menu_groups')->insertGetId([
                    'category_id' => $catId, 'gender' => 'all', 'key' => $key,
                    'name' => $en, 'name_fr' => $fr, 'name_ar' => $ar, 'sort_order' => $gi,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($ordered as $ii => $subId) {
                    DB::table('gender_menu_items')->insert([
                        'group_id' => $groupId, 'subcategory_id' => $subId, 'sort_order' => $ii,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('gender_menu_groups')->where('gender', 'all')->pluck('id');
        DB::table('gender_menu_items')->whereIn('group_id', $ids)->delete();
        DB::table('gender_menu_groups')->whereIn('id', $ids)->delete();
    }
};
