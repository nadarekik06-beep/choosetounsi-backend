<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubcategoryAndAttributeSeeder extends Seeder
{
    public function run(): void
    {
        // ─────────────────────────────────────────────────────────
        // STEP 1 — Fetch category IDs
        // ─────────────────────────────────────────────────────────
        $cats = DB::table('categories')->pluck('id', 'slug');

        // ─────────────────────────────────────────────────────────
        // STEP 2 — Subcategories per category
        // ─────────────────────────────────────────────────────────
        $subcategoryMap = [
            'fashion-clothing' => [
                ['name' => 'T-Shirt',       'name_ar' => 'تيشيرت'],
                ['name' => 'Dress',         'name_ar' => 'فستان'],
                ['name' => 'Shirt',         'name_ar' => 'قميص'],
                ['name' => 'Jeans',         'name_ar' => 'جينز'],
                ['name' => 'Denim Jacket',  'name_ar' => 'جاكيت جينز'],
                ['name' => 'Shorts',        'name_ar' => 'شورت'],
                ['name' => 'Sweatshirt',    'name_ar' => 'سويتشيرت'],
                ['name' => 'Sneakers',      'name_ar' => 'أحذية رياضية'],
                ['name' => 'High Heels',    'name_ar' => 'كعب عالي'],
                ['name' => 'Sandals',       'name_ar' => 'صندل'],
                ['name' => 'Handbag',       'name_ar' => 'حقيبة يد'],
                ['name' => 'Backpack',      'name_ar' => 'حقيبة ظهر'],
                ['name' => 'Watch',         'name_ar' => 'ساعة'],
                ['name' => 'Scarf',         'name_ar' => 'وشاح'],
                ['name' => 'Pyjama Set',    'name_ar' => 'بيجامة'],
                ['name' => 'Sportswear',    'name_ar' => 'ملابس رياضية'],
            ],
            'electronics-tech' => [
                ['name' => 'Smartphone',       'name_ar' => 'هاتف ذكي'],
                ['name' => 'Laptop',           'name_ar' => 'حاسوب محمول'],
                ['name' => 'Tablet',           'name_ar' => 'تابلت'],
                ['name' => 'Earphones',        'name_ar' => 'سماعات أذن'],
                ['name' => 'Headphones',       'name_ar' => 'سماعات رأس'],
                ['name' => 'Bluetooth Speaker','name_ar' => 'مكبر صوت'],
                ['name' => 'Smartwatch',       'name_ar' => 'ساعة ذكية'],
                ['name' => 'Phone Case',       'name_ar' => 'غطاء هاتف'],
                ['name' => 'Charger',          'name_ar' => 'شاحن'],
                ['name' => 'USB Drive',        'name_ar' => 'ذاكرة USB'],
                ['name' => 'Gaming Console',   'name_ar' => 'وحدة ألعاب'],
                ['name' => 'TV',               'name_ar' => 'تلفاز'],
            ],
            'home-living' => [
                ['name' => 'Sofa',             'name_ar' => 'أريكة'],
                ['name' => 'Bed Frame',        'name_ar' => 'سرير'],
                ['name' => 'Dining Table',     'name_ar' => 'طاولة طعام'],
                ['name' => 'Rug',              'name_ar' => 'سجادة'],
                ['name' => 'Curtains',         'name_ar' => 'ستائر'],
                ['name' => 'Wall Art',         'name_ar' => 'لوحة جدارية'],
                ['name' => 'Candle',           'name_ar' => 'شمعة'],
                ['name' => 'Crockery Set',     'name_ar' => 'طقم أواني'],
                ['name' => 'Bed Sheets',       'name_ar' => 'ملاءات سرير'],
                ['name' => 'Storage Box',      'name_ar' => 'صندوق تخزين'],
            ],
            'food-grocery' => [
                ['name' => 'Olive Oil',        'name_ar' => 'زيت زيتون'],
                ['name' => 'Honey',            'name_ar' => 'عسل'],
                ['name' => 'Dates',            'name_ar' => 'تمر'],
                ['name' => 'Harissa',          'name_ar' => 'هريسة'],
                ['name' => 'Spices',           'name_ar' => 'بهارات'],
                ['name' => 'Tea',              'name_ar' => 'شاي'],
                ['name' => 'Coffee',           'name_ar' => 'قهوة'],
                ['name' => 'Canned Goods',     'name_ar' => 'معلبات'],
                ['name' => 'Organic Products', 'name_ar' => 'منتجات عضوية'],
            ],
            'beauty-personal-care' => [
                ['name' => 'Moisturiser',      'name_ar' => 'مرطب'],
                ['name' => 'Serum',            'name_ar' => 'سيروم'],
                ['name' => 'Face Mask',        'name_ar' => 'قناع وجه'],
                ['name' => 'Lipstick',         'name_ar' => 'أحمر شفاه'],
                ['name' => 'Foundation',       'name_ar' => 'كريم أساس'],
                ['name' => 'Mascara',          'name_ar' => 'ماسكارا'],
                ['name' => 'Eau de Parfum',    'name_ar' => 'عطر'],
                ['name' => 'Shampoo',          'name_ar' => 'شامبو'],
                ['name' => 'Hair Mask',        'name_ar' => 'قناع شعر'],
                ['name' => 'Argan Oil',        'name_ar' => 'زيت أرغان'],
            ],
            'sports-outdoors' => [
                ['name' => 'Running Shoes',    'name_ar' => 'أحذية الجري'],
                ['name' => 'Football Kit',     'name_ar' => 'طقم كرة قدم'],
                ['name' => 'Yoga Mat',         'name_ar' => 'حصيرة يوغا'],
                ['name' => 'Weights',          'name_ar' => 'أثقال'],
                ['name' => 'Bicycle',          'name_ar' => 'دراجة'],
                ['name' => 'Swimming Gear',    'name_ar' => 'معدات سباحة'],
                ['name' => 'Sports T-Shirt',   'name_ar' => 'تيشيرت رياضي'],
                ['name' => 'Tracksuit',        'name_ar' => 'بدلة رياضية'],
            ],
            'arts-crafts' => [
                ['name' => 'Acrylic Paint',    'name_ar' => 'طلاء أكريليك'],
                ['name' => 'Canvas',           'name_ar' => 'لوحة قماشية'],
                ['name' => 'Pottery',          'name_ar' => 'فخار'],
                ['name' => 'Embroidery Kit',   'name_ar' => 'طقم تطريز'],
                ['name' => 'Handmade Jewelry', 'name_ar' => 'مجوهرات يدوية'],
                ['name' => 'Knitting Yarn',    'name_ar' => 'خيط تريكو'],
            ],
            'books-stationery' => [
                ['name' => 'Novel',            'name_ar' => 'رواية'],
                ['name' => 'Comic / Manga',    'name_ar' => 'مانغا'],
                ['name' => 'School Textbook',  'name_ar' => 'كتاب مدرسي'],
                ['name' => 'Notebook',         'name_ar' => 'دفتر'],
                ['name' => 'Pen Set',          'name_ar' => 'طقم أقلام'],
                ['name' => 'Planner',          'name_ar' => 'مخطط'],
            ],
            'kids-baby' => [
                ['name' => 'Plush Toy',        'name_ar' => 'لعبة محشوة'],
                ['name' => 'Educational Game', 'name_ar' => 'لعبة تعليمية'],
                ['name' => 'Baby Clothes',     'name_ar' => 'ملابس رضع'],
                ['name' => 'Stroller',         'name_ar' => 'عربة أطفال'],
                ['name' => 'Baby Bottle',      'name_ar' => 'رضاعة'],
                ['name' => 'Toy Car',          'name_ar' => 'سيارة ألعاب'],
            ],
            'automotive' => [
                ['name' => 'Car Accessory',    'name_ar' => 'إكسسوار سيارة'],
                ['name' => 'Car Seat Cover',   'name_ar' => 'غطاء مقعد'],
                ['name' => 'Motorcycle Gear',  'name_ar' => 'معدات موتوسيكل'],
                ['name' => 'Car Perfume',      'name_ar' => 'معطر سيارة'],
            ],
            'health-wellness' => [
                ['name' => 'Vitamins',         'name_ar' => 'فيتامينات'],
                ['name' => 'Protein Powder',   'name_ar' => 'بروتين'],
                ['name' => 'Medical Device',   'name_ar' => 'جهاز طبي'],
                ['name' => 'Essential Oil',    'name_ar' => 'زيت عطري'],
                ['name' => 'Herbal Tea',       'name_ar' => 'شاي أعشاب'],
            ],
        ];

        // Insert subcategories and track IDs
        $subcategoryIds = []; // slug => id

        foreach ($subcategoryMap as $catSlug => $subs) {
            if (!isset($cats[$catSlug])) continue;
            $catId = $cats[$catSlug];

            foreach ($subs as $order => $sub) {
                $slug = Str::slug($sub['name']);
                $id = DB::table('subcategories')->insertGetId([
                    'category_id' => $catId,
                    'name'        => $sub['name'],
                    'name_ar'     => $sub['name_ar'],
                    'slug'        => $slug,
                    'is_active'   => true,
                    'order'       => $order,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $subcategoryIds["{$catSlug}::{$slug}"] = $id;
            }
        }

        // ─────────────────────────────────────────────────────────
        // STEP 3 — Global attributes
        // ─────────────────────────────────────────────────────────
        $attributes = [
            // Clothing
            ['slug' => 'gender',        'name' => 'Gender',        'name_ar' => 'الجنس',        'type' => 'select',      'is_required' => true,  'is_filterable' => true,  'order' => 1],
            ['slug' => 'size',          'name' => 'Size',          'name_ar' => 'الحجم',        'type' => 'multiselect', 'is_required' => true,  'is_filterable' => true,  'order' => 2],
            ['slug' => 'color',         'name' => 'Color',         'name_ar' => 'اللون',        'type' => 'color',       'is_required' => false, 'is_filterable' => true,  'order' => 3],
            ['slug' => 'brand',         'name' => 'Brand',         'name_ar' => 'الماركة',      'type' => 'text',        'is_required' => false, 'is_filterable' => true,  'order' => 4],
            ['slug' => 'material',      'name' => 'Material',      'name_ar' => 'المادة',       'type' => 'select',      'is_required' => false, 'is_filterable' => true,  'order' => 5],
            ['slug' => 'sleeve-type',   'name' => 'Sleeve Type',   'name_ar' => 'نوع الكم',    'type' => 'select',      'is_required' => false, 'is_filterable' => true,  'order' => 6],
            ['slug' => 'fit',           'name' => 'Fit',           'name_ar' => 'التوافق',      'type' => 'select',      'is_required' => false, 'is_filterable' => true,  'order' => 7],
            // Shoes
            ['slug' => 'shoe-size',     'name' => 'Shoe Size',     'name_ar' => 'مقاس الحذاء', 'type' => 'multiselect', 'is_required' => true,  'is_filterable' => true,  'order' => 2],
            ['slug' => 'shoe-material', 'name' => 'Shoe Material', 'name_ar' => 'مادة الحذاء', 'type' => 'select',      'is_required' => false, 'is_filterable' => false, 'order' => 5],
            // Electronics
            ['slug' => 'storage',       'name' => 'Storage',       'name_ar' => 'التخزين',      'type' => 'select',      'is_required' => false, 'is_filterable' => true,  'order' => 3],
            ['slug' => 'ram',           'name' => 'RAM',           'name_ar' => 'الرام',        'type' => 'select',      'is_required' => false, 'is_filterable' => true,  'order' => 4],
            ['slug' => 'screen-size',   'name' => 'Screen Size',   'name_ar' => 'حجم الشاشة',  'type' => 'text',        'is_required' => false, 'is_filterable' => false, 'order' => 5],
            ['slug' => 'battery',       'name' => 'Battery (mAh)', 'name_ar' => 'البطارية',     'type' => 'number',      'is_required' => false, 'is_filterable' => false, 'order' => 6],
            ['slug' => 'os',            'name' => 'Operating System','name_ar' => 'نظام التشغيل','type' => 'select',     'is_required' => false, 'is_filterable' => true,  'order' => 7],
            ['slug' => 'connectivity',  'name' => 'Connectivity',  'name_ar' => 'الاتصال',      'type' => 'multiselect', 'is_required' => false, 'is_filterable' => false, 'order' => 8],
            // Food
            ['slug' => 'weight',        'name' => 'Weight / Volume','name_ar' => 'الوزن',       'type' => 'text',        'is_required' => true,  'is_filterable' => false, 'order' => 2],
            ['slug' => 'origin',        'name' => 'Origin',        'name_ar' => 'المصدر',       'type' => 'text',        'is_required' => false, 'is_filterable' => true,  'order' => 3],
            ['slug' => 'is-organic',    'name' => 'Organic',       'name_ar' => 'عضوي',         'type' => 'boolean',     'is_required' => false, 'is_filterable' => true,  'order' => 4],
            ['slug' => 'expiry-months', 'name' => 'Shelf Life (months)','name_ar' => 'مدة الصلاحية','type' => 'number', 'is_required' => false, 'is_filterable' => false, 'order' => 5],
            // Beauty
            ['slug' => 'skin-type',     'name' => 'Skin Type',     'name_ar' => 'نوع البشرة',  'type' => 'multiselect', 'is_required' => false, 'is_filterable' => true,  'order' => 3],
            ['slug' => 'volume-ml',     'name' => 'Volume (ml)',   'name_ar' => 'الحجم',        'type' => 'number',      'is_required' => false, 'is_filterable' => false, 'order' => 4],
            ['slug' => 'fragrance-family','name' => 'Fragrance Family','name_ar' => 'عائلة العطر','type' => 'select',   'is_required' => false, 'is_filterable' => true,  'order' => 3],
            // Sports
            ['slug' => 'sport-type',    'name' => 'Sport',         'name_ar' => 'الرياضة',      'type' => 'select',      'is_required' => false, 'is_filterable' => true,  'order' => 2],
            // General
            ['slug' => 'condition',     'name' => 'Condition',     'name_ar' => 'الحالة',       'type' => 'select',      'is_required' => true,  'is_filterable' => true,  'order' => 1],
            ['slug' => 'age-group',     'name' => 'Age Group',     'name_ar' => 'الفئة العمرية','type' => 'select',      'is_required' => false, 'is_filterable' => true,  'order' => 8],
        ];

        $attrIds = []; // slug => id
        foreach ($attributes as $attr) {
            $id = DB::table('attributes')->insertGetId([
                'name'          => $attr['name'],
                'name_ar'       => $attr['name_ar'],
                'slug'          => $attr['slug'],
                'type'          => $attr['type'],
                'is_required'   => $attr['is_required'],
                'is_filterable' => $attr['is_filterable'],
                'is_visible'    => true,
                'order'         => $attr['order'],
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            $attrIds[$attr['slug']] = $id;
        }

        // ─────────────────────────────────────────────────────────
        // STEP 4 — Attribute options
        // ─────────────────────────────────────────────────────────
        $options = [
            'gender'   => ['Men', 'Women', 'Unisex', 'Kids'],
            'size'     => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'One Size'],
            'shoe-size'=> ['35','36','37','38','39','40','41','42','43','44','45','46'],
            'material' => ['Cotton','Polyester','Linen','Silk','Wool','Denim','Leather','Synthetic','Mixed'],
            'sleeve-type'=>['Sleeveless','Short Sleeve','3/4 Sleeve','Long Sleeve','Off-Shoulder'],
            'fit'      => ['Slim Fit','Regular Fit','Loose Fit','Oversized'],
            'shoe-material'=>['Leather','Suede','Canvas','Mesh','Synthetic'],
            'storage'  => ['16GB','32GB','64GB','128GB','256GB','512GB','1TB','2TB'],
            'ram'      => ['2GB','4GB','6GB','8GB','12GB','16GB','32GB'],
            'os'       => ['Android','iOS','Windows','macOS','Linux'],
            'connectivity'=>['Wi-Fi','Bluetooth','NFC','4G','5G','USB-C','USB-A'],
            'skin-type'=> ['Normal','Oily','Dry','Combination','Sensitive'],
            'fragrance-family'=>['Floral','Woody','Oriental','Fresh','Citrus','Gourmand'],
            'sport-type'=>['Football','Basketball','Running','Swimming','Cycling','Yoga','Fitness','Tennis','Hiking'],
            'condition'=> ['New','Like New','Used - Good','Used - Acceptable'],
            'age-group'=> ['Baby (0-2)','Kids (3-12)','Teens (13-17)','Adults (18+)','All Ages'],
        ];

        foreach ($options as $attrSlug => $values) {
            if (!isset($attrIds[$attrSlug])) continue;
            foreach ($values as $order => $value) {
                DB::table('attribute_options')->insert([
                    'attribute_id' => $attrIds[$attrSlug],
                    'value'        => $value,
                    'order'        => $order,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }

        // Color options with hex
        $colors = [
            ['Black','#000000'],['White','#FFFFFF'],['Red','#DC2626'],['Blue','#2563EB'],
            ['Green','#16A34A'],['Yellow','#EAB308'],['Pink','#EC4899'],['Purple','#9333EA'],
            ['Orange','#F97316'],['Brown','#92400E'],['Grey','#6B7280'],['Navy','#1E3A5F'],
            ['Beige','#D4A574'],['Gold','#D4AF37'],['Silver','#C0C0C0'],
        ];
        foreach ($colors as $order => [$value, $hex]) {
            DB::table('attribute_options')->insert([
                'attribute_id' => $attrIds['color'],
                'value'        => $value,
                'color_hex'    => $hex,
                'order'        => $order,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // ─────────────────────────────────────────────────────────
        // STEP 5 — Map attributes → subcategories
        // ─────────────────────────────────────────────────────────
        // Helper
        $mapAttrs = function (string $catSlug, string $subSlug, array $attrSlugs, array $required = []) use (&$subcategoryIds, &$attrIds) {
            $key = "{$catSlug}::{$subSlug}";
            if (!isset($subcategoryIds[$key])) return;
            $subId = $subcategoryIds[$key];
            foreach ($attrSlugs as $order => $attrSlug) {
                if (!isset($attrIds[$attrSlug])) continue;
                DB::table('subcategory_attributes')->insert([
                    'subcategory_id' => $subId,
                    'attribute_id'   => $attrIds[$attrSlug],
                    'is_required'    => in_array($attrSlug, $required),
                    'order'          => $order,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        };

        // Fashion subcategories
        $clothingAttrs   = ['condition', 'gender', 'size', 'color', 'brand', 'material', 'sleeve-type', 'fit', 'age-group'];
        $clothingReq     = ['condition', 'gender', 'size'];
        $shoeAttrs       = ['condition', 'gender', 'shoe-size', 'color', 'brand', 'shoe-material'];
        $shoeReq         = ['condition', 'shoe-size'];
        $bagAttrs        = ['condition', 'color', 'brand', 'material'];
        $accessoryAttrs  = ['condition', 'color', 'brand'];

        foreach (['t-shirt','dress','shirt','jeans','denim-jacket','shorts','sweatshirt','pyjama-set','sportswear'] as $sub) {
            $mapAttrs('fashion-clothing', $sub, $clothingAttrs, $clothingReq);
        }
        foreach (['sneakers','high-heels','sandals'] as $sub) {
            $mapAttrs('fashion-clothing', $sub, $shoeAttrs, $shoeReq);
        }
        foreach (['handbag','backpack'] as $sub) {
            $mapAttrs('fashion-clothing', $sub, $bagAttrs, ['condition']);
        }
        foreach (['watch','scarf'] as $sub) {
            $mapAttrs('fashion-clothing', $sub, $accessoryAttrs, ['condition']);
        }

        // Electronics
        $mapAttrs('electronics-tech', 'smartphone',       ['condition','brand','storage','ram','screen-size','battery','os','color','connectivity'], ['condition','brand','storage']);
        $mapAttrs('electronics-tech', 'laptop',           ['condition','brand','storage','ram','screen-size','os','color'], ['condition','brand','storage','ram']);
        $mapAttrs('electronics-tech', 'tablet',           ['condition','brand','storage','ram','screen-size','os','connectivity'], ['condition','brand']);
        $mapAttrs('electronics-tech', 'earphones',        ['condition','brand','color','connectivity'], ['condition']);
        $mapAttrs('electronics-tech', 'headphones',       ['condition','brand','color','connectivity'], ['condition']);
        $mapAttrs('electronics-tech', 'bluetooth-speaker',['condition','brand','color','connectivity'], ['condition']);
        $mapAttrs('electronics-tech', 'smartwatch',       ['condition','brand','color','os','connectivity'], ['condition']);
        $mapAttrs('electronics-tech', 'phone-case',       ['brand','color','material'], []);
        $mapAttrs('electronics-tech', 'charger',          ['condition','brand','connectivity'], ['condition']);
        $mapAttrs('electronics-tech', 'usb-drive',        ['condition','brand','storage'], ['storage']);
        $mapAttrs('electronics-tech', 'gaming-console',   ['condition','brand','storage','color'], ['condition']);
        $mapAttrs('electronics-tech', 'tv',               ['condition','brand','screen-size'], ['condition']);

        // Food
        foreach (['olive-oil','honey','dates','harissa','spices','tea','coffee','canned-goods','organic-products'] as $sub) {
            $mapAttrs('food-grocery', $sub, ['weight','origin','is-organic','expiry-months'], ['weight']);
        }

        // Beauty
        foreach (['moisturiser','serum','face-mask','shampoo','hair-mask','argan-oil'] as $sub) {
            $mapAttrs('beauty-personal-care', $sub, ['condition','brand','skin-type','volume-ml'], []);
        }
        foreach (['lipstick','foundation','mascara'] as $sub) {
            $mapAttrs('beauty-personal-care', $sub, ['condition','brand','color','skin-type'], []);
        }
        $mapAttrs('beauty-personal-care', 'eau-de-parfum', ['condition','brand','fragrance-family','volume-ml'], []);

        // Sports
        foreach (['running-shoes','football-kit','yoga-mat','weights','bicycle','swimming-gear','sports-t-shirt','tracksuit'] as $sub) {
            $mapAttrs('sports-outdoors', $sub, ['condition','brand','color','sport-type','size'], ['condition']);
        }

        // Kids
        $mapAttrs('kids-baby', 'baby-clothes',     ['condition','size','color','material','age-group'], ['condition','age-group']);
        $mapAttrs('kids-baby', 'plush-toy',        ['condition','color','age-group'], ['condition','age-group']);
        $mapAttrs('kids-baby', 'educational-game', ['condition','age-group'], ['condition','age-group']);
        $mapAttrs('kids-baby', 'stroller',         ['condition','brand','color'], ['condition']);
        $mapAttrs('kids-baby', 'baby-bottle',      ['condition','brand','material'], ['condition']);
        $mapAttrs('kids-baby', 'toy-car',          ['condition','color','age-group'], ['condition']);

        // Arts & Crafts — minimal attributes
        foreach (['acrylic-paint','canvas','pottery','embroidery-kit','handmade-jewelry','knitting-yarn'] as $sub) {
            $mapAttrs('arts-crafts', $sub, ['condition','color','brand'], []);
        }

        // Books & Stationery
        foreach (['novel','comic-manga','school-textbook','notebook','pen-set','planner'] as $sub) {
            $mapAttrs('books-stationery', $sub, ['condition','brand'], []);
        }

        // Automotive
        foreach (['car-accessory','car-seat-cover','motorcycle-gear','car-perfume'] as $sub) {
            $mapAttrs('automotive', $sub, ['condition','brand','color'], ['condition']);
        }

        // Health & Wellness
        foreach (['vitamins','protein-powder','medical-device','essential-oil','herbal-tea'] as $sub) {
            $mapAttrs('health-wellness', $sub, ['condition','brand','weight','origin'], []);
        }

        $this->command->info('✅ SubcategoryAndAttributeSeeder completed.');
    }
}