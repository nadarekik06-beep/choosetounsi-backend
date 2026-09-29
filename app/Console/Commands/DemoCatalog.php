<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductTranslator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * DEV ONLY — fills the local shop with a realistic demo catalog so every
 * homepage row has something to show:
 *
 *   5 approved sellers (one per niche), 80 products over 9 categories with
 *   generated images, 12 demo clients with ~70 orders (best sellers) and
 *   reviews (top rated), 7 days of guest views/clicks (trending), 5 sponsored
 *   products, 1 live flash sale, and a demo shopper with favourites, a followed
 *   seller, recent views and a past order (personalized rows).
 *
 * Everything it creates is recognisable: users *@choosetounsi.test,
 * product slugs "demo-…", images in storage/app/public/products/demo/.
 * Undo it by restoring the mysqldump you took before running it.
 *
 *   php artisan demo:catalog
 *   php artisan demo:catalog --python="C:\path\to\python.exe"
 */
class DemoCatalog extends Command
{
    protected $signature = 'demo:catalog {--python= : Python with Pillow (default: the AI service venv)}';
    protected $description = 'DEV ONLY: seed a demo catalog (sellers, products, orders, reviews, activity)';

    /** Demo shopper login (test account for the local storefront only). */
    const SHOPPER_EMAIL    = 'demo.shopper@choosetounsi.test';
    const SHOPPER_PASSWORD = 'DemoShopper#2026';
    const DEMO_DOMAIN      = '@choosetounsi.test';

    /** [key, business name, plan, owner name, wilaya] */
    const SELLERS = [
        'moda'   => ['Dar El Moda',      'black', 'Amira Ben Salah', 'Tunis'],
        'tech'   => ['TechZone Tunis',   'red',   'Karim Trabelsi',  'Ariana'],
        'bayti'  => ['Bayti Home & Craft', 'free', 'Leila Gharbi',   'Nabeul'],
        'sahel'  => ['Saveurs du Sahel', 'red',   'Hedi Mansour',    'Sousse'],
        'jasmin' => ['Jasmin Beauty',    'free',  'Sonia Jaziri',    'Sfax'],
    ];

    /** category slug => [light, dark] gradient for images */
    const COLORS = [
        'fashion-clothing'     => ['#fbcfe8', '#9d174d'],
        'sports-outdoors'      => ['#bbf7d0', '#166534'],
        'electronics-tech'     => ['#bfdbfe', '#1e3a8a'],
        'home-living'          => ['#99f6e4', '#0f766e'],
        'arts-crafts'          => ['#fde68a', '#92400e'],
        'food-grocery'         => ['#fed7aa', '#9a3412'],
        'health-wellness'      => ['#d9f99d', '#3f6212'],
        'beauty-personal-care' => ['#f5d0fe', '#86198f'],
        'kids-baby'            => ['#bae6fd', '#0369a1'],
    ];

    /** seller => [[category, subcategory, name, price, brand], …]  (80 products) */
    const PRODUCTS = [
        'moda' => [
            ['fashion-clothing', 't-shirt', 'Classic Cotton T-Shirt', 35, 'Medina Wear'],
            ['fashion-clothing', 't-shirt', 'Oversized Graphic Tee', 45, 'Medina Wear'],
            ['fashion-clothing', 'shirt', 'Linen Summer Shirt', 79, 'Medina Wear'],
            ['fashion-clothing', 'dress', 'Floral Maxi Dress', 129, 'Medina Wear'],
            ['fashion-clothing', 'dress', 'Evening Satin Dress', 189, 'Medina Wear'],
            ['fashion-clothing', 'jeans', 'Slim Fit Jeans', 99, 'Medina Wear'],
            ['fashion-clothing', 'jeans', 'High Waist Mom Jeans', 109, 'Medina Wear'],
            ['fashion-clothing', 'denim-jacket', 'Vintage Denim Jacket', 149, 'Medina Wear'],
            ['fashion-clothing', 'sneakers', 'Urban Runner Sneakers', 159, 'Carthago Sport'],
            ['fashion-clothing', 'sweatshirt', 'Hooded Sweatshirt', 89, 'Medina Wear'],
            ['fashion-clothing', 'handbag', 'Leather Tote Bag', 139, 'Medina Wear'],
            ['fashion-clothing', 'scarf', 'Silk Scarf Jasmine Print', 55, 'Medina Wear'],
            ['fashion-clothing', 'sandals', 'Handmade Leather Sandals', 69, 'Medina Wear'],
            ['fashion-clothing', 'watch', 'Minimalist Steel Watch', 199, 'Medina Wear'],
            ['sports-outdoors', 'running-shoes', 'Trail Running Shoes', 179, 'Carthago Sport'],
            ['sports-outdoors', 'yoga-mat', 'Eco Cork Yoga Mat', 65, 'Carthago Sport'],
            ['sports-outdoors', 'tracksuit', 'Training Tracksuit', 119, 'Carthago Sport'],
            ['sports-outdoors', 'weights', 'Adjustable Dumbbells 10kg', 149, 'Carthago Sport'],
            ['sports-outdoors', 'football-kit', 'Football Home Kit', 85, 'Carthago Sport'],
            ['sports-outdoors', 'swimming-gear', 'Pro Swim Goggles', 39, 'Carthago Sport'],
        ],
        'tech' => [
            ['electronics-tech', 'smartphone', 'Smartphone 128GB Dual SIM', 899, 'Volta'],
            ['electronics-tech', 'earphones', 'True Wireless Earbuds', 129, 'Nomad Audio'],
            ['electronics-tech', 'earphones', 'Sport Neckband Earphones', 69, 'Nomad Audio'],
            ['electronics-tech', 'headphones', 'Noise Cancelling Headphones', 349, 'Nomad Audio'],
            ['electronics-tech', 'headphones', 'Kids Safe Headphones', 79, 'Nomad Audio'],
            ['electronics-tech', 'laptop', 'Ultrabook 14 inch', 2499, 'Volta'],
            ['electronics-tech', 'tablet', 'Android Tablet 10 inch', 699, 'Volta'],
            ['electronics-tech', 'smartwatch', 'Fitness Smartwatch', 229, 'Volta'],
            ['electronics-tech', 'smartwatch', 'Smartwatch Strap Set', 35, 'Volta'],
            ['electronics-tech', 'bluetooth-speaker', 'Portable Bluetooth Speaker', 149, 'Nomad Audio'],
            ['electronics-tech', 'charger', '65W Fast Charger', 59, 'Volta'],
            ['electronics-tech', 'phone-case', 'Shockproof Phone Case', 29, 'Volta'],
            ['electronics-tech', 'usb-drive', 'USB-C Flash Drive 128GB', 45, 'Volta'],
            ['electronics-tech', 'gaming-console', 'Retro Game Console', 259, 'Volta'],
            ['electronics-tech', 'tv', 'Smart TV 43 inch', 1099, 'Volta'],
        ],
        'bayti' => [
            ['home-living', 'sofa', 'Linen 3-Seater Sofa', 1899, 'Bayti'],
            ['home-living', 'rug', 'Berber Wool Rug', 459, 'Bayti'],
            ['home-living', 'candle', 'Orange Blossom Candle', 35, 'Bayti'],
            ['home-living', 'crockery-set', 'Nabeul Ceramic Plate Set', 149, 'Kairouan Crafts'],
            ['home-living', 'curtains', 'Blackout Linen Curtains', 119, 'Bayti'],
            ['home-living', 'bed-sheets', 'Cotton Bed Sheet Set', 99, 'Bayti'],
            ['home-living', 'wall-art', 'Sidi Bou Said Canvas Print', 89, 'Bayti'],
            ['home-living', 'storage-box', 'Woven Storage Basket', 49, 'Kairouan Crafts'],
            ['home-living', 'dining-table', 'Olive Wood Dining Table', 1299, 'Bayti'],
            ['arts-crafts', 'pottery', 'Hand-painted Pottery Bowl', 59, 'Kairouan Crafts'],
            ['arts-crafts', 'handmade-jewelry', 'Silver Khomsa Pendant', 119, 'Kairouan Crafts'],
            ['arts-crafts', 'embroidery-kit', 'Embroidery Starter Kit', 45, 'Kairouan Crafts'],
            ['arts-crafts', 'acrylic-paint', 'Acrylic Paint Set 24 Colors', 55, 'Kairouan Crafts'],
            ['arts-crafts', 'canvas', 'Stretched Canvas Pack', 39, 'Kairouan Crafts'],
            ['arts-crafts', 'knitting-yarn', 'Merino Knitting Yarn', 29, 'Kairouan Crafts'],
        ],
        'sahel' => [
            ['food-grocery', 'olive-oil', 'Extra Virgin Olive Oil 1L', 32, 'Sahel Farms'],
            ['food-grocery', 'olive-oil', 'Organic Olive Oil 500ml', 24, 'Sahel Farms'],
            ['food-grocery', 'honey', 'Thyme Honey 500g', 45, 'Sahel Farms'],
            ['food-grocery', 'honey', 'Wild Flower Honey 1kg', 79, 'Sahel Farms'],
            ['food-grocery', 'dates', 'Deglet Nour Dates 1kg', 18, 'Sahel Farms'],
            ['food-grocery', 'harissa', 'Traditional Harissa 380g', 9, 'Sahel Farms'],
            ['food-grocery', 'spices', 'Tabil Spice Blend', 12, 'Sahel Farms'],
            ['food-grocery', 'tea', 'Green Tea with Mint', 15, 'Sahel Farms'],
            ['food-grocery', 'coffee', 'Ground Arabica Coffee', 22, 'Sahel Farms'],
            ['food-grocery', 'canned-goods', 'Tuna in Olive Oil', 11, 'Sahel Farms'],
            ['food-grocery', 'organic-products', 'Organic Couscous 1kg', 14, 'Sahel Farms'],
            ['health-wellness', 'vitamins', 'Vitamin D3 + K2', 49, 'VitaLine'],
            ['health-wellness', 'herbal-tea', 'Chamomile Herbal Tea', 16, 'VitaLine'],
            ['health-wellness', 'protein-powder', 'Whey Isolate 2kg', 239, 'VitaLine'],
            ['health-wellness', 'essential-oil', 'Rosemary Essential Oil', 35, 'VitaLine'],
            ['health-wellness', 'medical-device', 'Digital Thermometer', 29, 'VitaLine'],
        ],
        'jasmin' => [
            ['beauty-personal-care', 'argan-oil', 'Pure Argan Oil 100ml', 59, 'Jasmin'],
            ['beauty-personal-care', 'eau-de-parfum', 'Jasmine Eau de Parfum', 139, 'Jasmin'],
            ['beauty-personal-care', 'face-mask', 'Rhassoul Clay Mask', 25, 'Jasmin'],
            ['beauty-personal-care', 'foundation', 'Liquid Foundation', 49, 'Jasmin'],
            ['beauty-personal-care', 'hair-mask', 'Hair Repair Mask', 35, 'Jasmin'],
            ['beauty-personal-care', 'lipstick', 'Matte Lipstick', 29, 'Jasmin'],
            ['beauty-personal-care', 'mascara', 'Volume Mascara', 32, 'Jasmin'],
            ['beauty-personal-care', 'moisturiser', 'Hydrating Moisturiser', 45, 'Jasmin'],
            ['beauty-personal-care', 'serum', 'Vitamin C Serum', 69, 'Jasmin'],
            ['beauty-personal-care', 'shampoo', 'Argan Shampoo', 27, 'Jasmin'],
            ['kids-baby', 'plush-toy', 'Soft Camel Plush Toy', 39, 'Petit Pas'],
            ['kids-baby', 'baby-clothes', 'Organic Baby Bodysuit Set', 59, 'Petit Pas'],
            ['kids-baby', 'educational-game', 'Wooden Alphabet Puzzle', 45, 'Petit Pas'],
            ['kids-baby', 'baby-bottle', 'Anti-colic Baby Bottle', 25, 'Petit Pas'],
        ],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed demo data in production.');
            return self::FAILURE;
        }
        if (DB::table('users')->where('email', self::SHOPPER_EMAIL)->exists()) {
            $this->error('Demo data already exists (' . self::SHOPPER_EMAIL . '). Restore your backup first to re-seed.');
            return self::FAILURE;
        }

        // Saving an approved product queues a Groq translation — never from a demo seed.
        app()->instance(ProductTranslator::class, new class {
            public function __call($method, $args) { return null; }
        });
        mt_srand(20260928);   // reproducible demo

        $cats = DB::table('categories')->pluck('id', 'slug');
        $subs = DB::table('subcategories')->pluck('id', 'slug');
        $brandAttr = DB::table('attributes')->where('slug', 'brand')->value('id');

        // 1. Images first (outside the transaction — slow, and harmless if the seed fails)
        $specs = [];
        foreach (self::PRODUCTS as $rows) {
            foreach ($rows as [$cat, , $name, , $brand]) {
                $specs[] = [
                    'path'     => storage_path('app/public/products/demo/' . Str::slug($name) . '.png'),
                    'title'    => $name,
                    'category' => DB::table('categories')->where('slug', $cat)->value('name'),
                    'brand'    => $brand,
                    'colors'   => self::COLORS[$cat],
                ];
            }
        }
        $this->info('Generating ' . count($specs) . ' product images…');
        if (!$this->generateImages($specs)) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($cats, $subs, $brandAttr) {
            $password = Hash::make(Str::random(32));   // sellers/clients never log in

            // 2. Sellers
            $sellerIds = [];
            foreach (self::SELLERS as $key => [$business, $plan, $owner, $wilaya]) {
                $id = DB::table('users')->insertGetId([
                    'name' => $owner, 'email' => "demo.seller.{$key}" . self::DEMO_DOMAIN, 'password' => $password,
                    'role' => 'seller', 'is_active' => true, 'is_approved' => true, 'email_verified_at' => now(),
                    'onboarding_completed' => true, 'created_at' => now()->subDays(120), 'updated_at' => now(),
                ]);
                DB::table('seller_applications')->insert([
                    'user_id' => $id, 'full_name' => $owner, 'phone_number' => '+216 20 000 00' . count($sellerIds),
                    'business_name' => $business, 'business_category' => self::PRODUCTS[$key][0][0],
                    'business_description' => "{$business} — demo shop", 'wilaya' => $wilaya, 'city' => $wilaya,
                    'status' => 'approved', 'plan' => $plan, 'reviewed_at' => now()->subDays(118),
                    'created_at' => now()->subDays(120), 'updated_at' => now(),
                ]);
                $sellerIds[$key] = $id;
            }

            // 3. Products (+ image, brand, price history via the model)
            $products = [];
            foreach (self::PRODUCTS as $key => $rows) {
                foreach ($rows as [$cat, $sub, $name, $price, $brand]) {
                    $slug = 'demo-' . Str::slug($name);
                    $p = Product::create([
                        'seller_id' => $sellerIds[$key], 'category_id' => $cats[$cat] ?? null, 'subcategory_id' => $subs[$sub] ?? null,
                        'name' => $name, 'slug' => $slug,
                        'description' => "{$name} by {$brand}. Demo product for the ChooseTounsi storefront.",
                        'short_description' => "{$brand} · demo product",
                        'price' => $price, 'stock' => mt_rand(0, 9) === 0 ? mt_rand(1, 4) : mt_rand(8, 120),
                        'is_approved' => true, 'is_active' => true, 'featured' => mt_rand(0, 9) === 0,
                        'views' => mt_rand(5, 400),
                    ]);
                    $created = now()->subDays(mt_rand(0, 75))->subMinutes(mt_rand(0, 1440));
                    DB::table('products')->where('id', $p->id)->update(['created_at' => $created, 'updated_at' => $created]);
                    DB::table('product_images')->insert([
                        'product_id' => $p->id, 'image_path' => 'products/demo/' . Str::slug($name) . '.png',
                        'is_primary' => true, 'order' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($brandAttr) {
                        DB::table('product_attribute_values')->insert(['product_id' => $p->id, 'attribute_id' => $brandAttr, 'value' => $brand, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $products[] = ['id' => $p->id, 'seller' => $sellerIds[$key], 'price' => $price, 'cat' => $cat];
                }
            }

            // Popularity skew: a handful of products sell and get noticed far more than others.
            $weights = [];
            foreach ($products as $i => $p) {
                $weights[$i] = mt_rand(1, 10) === 1 ? mt_rand(12, 25) : mt_rand(1, 6);
            }
            $pickProduct = function () use ($products, $weights) {
                $r = mt_rand(1, array_sum($weights));
                foreach ($weights as $i => $w) {
                    if (($r -= $w) <= 0) {
                        return $products[$i];
                    }
                }
                return $products[0];
            };

            // 4. Demo clients, orders (→ best sellers) and reviews (→ top rated)
            $clientIds = [];
            foreach (range(1, 12) as $n) {
                $clientIds[] = DB::table('users')->insertGetId([
                    'name' => "Demo Client {$n}", 'email' => "demo.client{$n}" . self::DEMO_DOMAIN, 'password' => $password,
                    'role' => 'client', 'is_active' => true, 'is_approved' => true, 'email_verified_at' => now(),
                    'onboarding_completed' => true, 'created_at' => now()->subDays(100), 'updated_at' => now(),
                ]);
            }
            $orders = 0;
            foreach (range(1, 70) as $_) {
                $client = $clientIds[array_rand($clientIds)];
                $items = [];
                foreach (range(1, mt_rand(1, 3)) as $__) {
                    $p = $pickProduct();
                    $items[$p['id']] = $p;
                }
                $this->order($client, array_values($items), mt_rand(1, 10) <= 8 ? 'delivered' : 'pending', mt_rand(0, 80), true);
                $orders++;
            }

            // 5. Sponsored products (5) and one live flash sale (3 products)
            $ids = array_column($products, 'id');
            $sponsored = [$ids[3], $ids[22], $ids[36], $ids[51], $ids[67]];
            foreach ($sponsored as $i => $pid) {
                $p = $products[array_search($pid, $ids, true)];
                DB::table('products')->where('id', $pid)->update(['is_sponsored' => true, 'sponsored_priority' => [70, 30, 30, 10, 10][$i], 'sponsored_at' => now()->subDays($i)]);
                DB::table('sponsorships')->insert([
                    'seller_id' => $p['seller'], 'product_id' => $pid, 'plan_type' => ['black', 'red', 'red', 'free', 'free'][$i],
                    'pricing_model' => 'legacy_daily',
                    'status' => 'active', 'start_at' => now()->subDays(2), 'end_at' => now()->addDays(14),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $flashIds = [$ids[9], $ids[27], $ids[44]];
            $promoId = DB::table('promotions')->insertGetId([
                'seller_id' => $products[9]['seller'], 'name' => 'Demo Flash Sale', 'type' => 'flash_sale',
                'discount_type' => 'percentage', 'discount_value' => 25, 'starts_at' => now()->subHour(),
                'ends_at' => now()->addDays(2), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($flashIds as $pid) {
                DB::table('promotion_products')->insert(['promotion_id' => $promoId, 'product_id' => $pid]);
            }

            // 6. Platform activity over the last 7 days (→ trending): 40 guest sessions
            $rows = [];
            foreach (range(1, 40) as $_) {
                $sid = (string) Str::uuid();
                foreach (range(1, mt_rand(3, 12)) as $__) {
                    $p = $pickProduct();
                    $at = now()->subMinutes(mt_rand(10, 7 * 1440));
                    foreach (mt_rand(1, 4) === 1 ? ['click', 'view', 'cart_add'] : ['click', 'view'] as $event) {
                        $rows[] = ['session_id' => $sid, 'product_id' => $p['id'], 'seller_id' => $p['seller'],
                            'category_id' => $cats[$p['cat']] ?? null, 'event_type' => $event,
                            'source_section' => $event === 'click' ? 'trending' : null, 'created_at' => $at];
                    }
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('user_interactions')->insert($chunk);
            }

            // 7. The demo shopper: beauty & wellness fan who follows Jasmin Beauty
            $shopper = DB::table('users')->insertGetId([
                'name' => 'Demo Shopper', 'email' => self::SHOPPER_EMAIL, 'password' => Hash::make(self::SHOPPER_PASSWORD),
                'role' => 'client', 'is_active' => true, 'is_approved' => true, 'email_verified_at' => now(),
                'onboarding_completed' => true, 'created_at' => now()->subDays(60), 'updated_at' => now(),
            ]);
            $by = fn (string $cat) => array_values(array_filter($products, fn ($p) => $p['cat'] === $cat));
            $beauty = $by('beauty-personal-care');
            $health = $by('health-wellness');
            $fashion = $by('fashion-clothing');
            DB::table('seller_follows')->insert(['user_id' => $shopper, 'seller_id' => $sellerIds['jasmin'], 'created_at' => now()->subDays(20), 'updated_at' => now()]);
            foreach ([$beauty[0], $beauty[1], $beauty[8], $health[0], $fashion[3]] as $i => $p) {
                DB::table('favorites')->insert(['user_id' => $shopper, 'product_id' => $p['id'], 'created_at' => now()->subDays($i + 1), 'updated_at' => now()]);
                $this->signal($shopper, $p, 'favorite_add', $i + 1, $cats);
            }
            foreach ([$beauty[2], $beauty[5], $beauty[7], $health[2], $fashion[0], $fashion[3], $fashion[11]] as $i => $p) {
                $this->signal($shopper, $p, 'view', $i * 0.2, $cats);
                if ($i < 3) {
                    $this->signal($shopper, $p, 'click', $i * 0.2, $cats, 'recommended');
                }
            }
            $this->signal($shopper, $beauty[4], 'cart_add', 1, $cats);
            $bought = [$beauty[3], $health[1]];
            $orderId = $this->order($shopper, $bought, 'delivered', 12, false);
            foreach ($bought as $p) {
                $this->signal($shopper, $p, 'purchase', 12, $cats, null, $orderId);
            }

            $this->info("Seeded 5 sellers, " . count($products) . " products, 12 clients, {$orders} orders, "
                . count($rows) . ' guest interactions, 5 sponsored products, 1 flash sale and the demo shopper.');
        });

        // Fresh pools / profiles on the next homepage load
        \Illuminate\Support\Facades\Cache::forget('reco:pools:v2');
        $this->line('Demo shopper: ' . self::SHOPPER_EMAIL . ' (password: see SHOPPER_PASSWORD in ' . __FILE__ . ')');
        return self::SUCCESS;
    }

    private function generateImages(array $specs): bool
    {
        $python = $this->option('python') ?: base_path('../choosetounsi-ai-service/venv/Scripts/python.exe');
        $json = tempnam(sys_get_temp_dir(), 'demo') . '.json';
        file_put_contents($json, json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $process = new Process([$python, database_path('demo/make_demo_images.py'), $json]);
        $process->setTimeout(300);
        $process->run();
        @unlink($json);

        if (!$process->isSuccessful()) {
            $this->error("Image generation failed with {$python}. Pass --python=<python with Pillow>.");
            $this->line($process->getErrorOutput());
            return false;
        }
        $this->line(trim($process->getOutput()));
        return true;
    }

    /** One order with a seller sub-order per seller; optional approved reviews. Returns the order id. */
    private function order(int $userId, array $items, string $status, int $daysAgo, bool $review): int
    {
        $at = now()->subDays($daysAgo)->subMinutes(mt_rand(0, 1440));
        $total = array_sum(array_column($items, 'price'));
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId, 'order_number' => 'DEMO-' . strtoupper(Str::random(10)), 'subtotal' => $total,
            'total_amount' => $total, 'shipping_fee' => 7, 'discount_amount' => 0, 'status' => $status,
            'payment_status' => $status === 'delivered' ? 'paid' : 'unpaid', 'payment_method' => 'cod',
            'wilaya' => 'Tunis', 'created_at' => $at, 'updated_at' => $at,
        ]);

        $bySeller = [];
        foreach ($items as $p) {
            $bySeller[$p['seller']][] = $p;
        }
        foreach ($bySeller as $sellerId => $sellerItems) {
            $sub = array_sum(array_column($sellerItems, 'price'));
            $soId = DB::table('seller_orders')->insertGetId([
                'order_id' => $orderId, 'seller_id' => $sellerId, 'status' => $status,
                'payment_status' => $status === 'delivered' ? 'paid' : 'unpaid', 'subtotal' => $sub,
                'seller_net_amount' => $sub, 'created_at' => $at, 'updated_at' => $at,
            ]);
            foreach ($sellerItems as $p) {
                $qty = mt_rand(1, 3);
                $itemId = DB::table('order_items')->insertGetId([
                    'order_id' => $orderId, 'seller_order_id' => $soId, 'product_id' => $p['id'], 'quantity' => $qty,
                    'unit_price' => $p['price'], 'price' => $p['price'], 'total' => $p['price'] * $qty, 'net_total' => $p['price'] * $qty,
                    'discount_amount' => 0, 'commission_percentage' => 0, 'commission_amount' => 0,
                    'seller_amount' => $p['price'] * $qty, 'plan_used' => 'free', 'created_at' => $at, 'updated_at' => $at,
                ]);
                if ($review && $status === 'delivered' && mt_rand(1, 10) <= 7) {
                    DB::table('reviews')->insert([
                        'user_id' => $userId, 'product_id' => $p['id'], 'order_item_id' => $itemId, 'seller_id' => $sellerId,
                        'rating' => [5, 5, 5, 4, 4, 4, 3, 5, 4, 2][mt_rand(0, 9)], 'body' => 'Demo review.',
                        'is_verified_purchase' => true, 'status' => 'approved',
                        'created_at' => $at->copy()->addDays(3), 'updated_at' => $at->copy()->addDays(3),
                    ]);
                }
            }
        }
        return $orderId;
    }

    private function signal(int $userId, array $p, string $event, float $daysAgo, $cats, ?string $section = null, ?int $orderId = null): void
    {
        DB::table('user_interactions')->insert([
            'user_id' => $userId, 'product_id' => $p['id'], 'seller_id' => $p['seller'], 'category_id' => $cats[$p['cat']] ?? null,
            'event_type' => $event, 'source_section' => $section, 'order_id' => $orderId,
            'created_at' => now()->subMinutes((int) ($daysAgo * 1440)),
        ]);
    }
}
