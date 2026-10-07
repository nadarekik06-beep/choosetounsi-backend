<?php

namespace Database\Seeders;

use App\Services\VisitorInsights\FunnelAggregator;
use App\Services\VisitorInsights\FunnelBenchmarks;
use App\Services\VisitorInsights\TrafficSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Visitor Insights demo: php artisan funnel:demo   (remove: php artisan funnel:demo --purge)
 *
 * 70 days of raw funnel events (impressions → clicks → views → carts → checkout →
 * orders, with sources and devices), rolled up by the real aggregator, for:
 *
 *  - funnel-demo-black   Black Pepper shop where every drop-off stage shows up:
 *        healthy · low CTR · price above the category · weak listing · variants out of stock
 *        at the cart · high delivery fee at checkout · invisible product (its subcategory is
 *        below the privacy floor → compared to the category) · too new to judge,
 *        plus two applied actions (one measured, one still measuring)
 *  - funnel-demo-peer-a…e  five shops, so the category passes the privacy floor (≥5 shops)
 *  - funnel-demo-new     Black Pepper shop with 2 products and almost no traffic (low-data state)
 *  - funnel-demo-empty   Black Pepper shop without products (empty state)
 *
 * Everything matches EMAIL_LIKE / CATEGORY_LIKE and nothing else (DemoCatalog
 * and Growth demo accounts share the domain).
 */
class FunnelDemoSeeder extends Seeder
{
    public const DOMAIN        = '@choosetounsi.test';
    public const EMAIL_LIKE    = 'funnel-demo-%@choosetounsi.test';
    public const CATEGORY_LIKE = 'demo-funnel-%';
    public const PASSWORD      = 'demo-funnel-2026';
    private const DAYS = 70;

    private CarbonImmutable $today;
    private int $category;
    private int $audio;
    private int $accessories;
    private ?string $image;
    private array $buyers = [];
    private array $ui = [];
    private array $fe = [];

    public function run(): void
    {
        mt_srand(4242);
        $this->today = CarbonImmutable::now(config('funnel.timezone'))->startOfDay();
        if (DB::table('users')->where('email', 'funnel-demo-black' . self::DOMAIN)->exists()) {
            $this->command?->warn('Funnel demo already present — run php artisan funnel:demo --fresh.');
            return;
        }
        $this->image = DB::table('product_images')->where('image_path', 'like', 'products/demo/%')->value('image_path');
        $s = Str::lower(Str::random(4));
        $this->category = DB::table('categories')->insertGetId([
            'name' => 'Démo Funnel — Audio', 'name_fr' => 'Démo Funnel — Audio', 'name_ar' => 'Démo Funnel — Audio',
            'slug' => "demo-funnel-audio-$s", 'is_active' => false, 'order' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audio = $this->subcategory('Casques & enceintes', "demo-funnel-headsets-$s");
        $this->accessories = $this->subcategory('Accessoires audio', "demo-funnel-accessories-$s");
        foreach (range(1, 25) as $i) $this->buyers[] = $this->user("funnel-demo-buyer-$i", "Client Funnel $i", 'client');

        // ── Peers: a healthy category (CTR ~10 %, view→cart ~9 %, cart→order ~45 %) ──
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $letter) {
            $peer = $this->seller("funnel-demo-peer-$letter", 'Boutique audio ' . strtoupper($letter), 'free');
            foreach ([70 + $i * 4, 82 + $i * 4, 90 + $i * 3, 64 + $i * 5] as $k => $price) {
                $p = $this->product($peer, 'Casque pair ' . strtoupper($letter) . ($k + 1) . ' — modèle confort sans fil', $price);
                $this->simulate($p, $peer, ['imp' => 45, 'ctr' => 0.10 + $k * 0.01, 'v2c' => 0.08 + $k * 0.01, 'c2k' => 0.7, 'k2o' => 0.65]);
            }
        }

        // ── The demo shop (Black Pepper) ──────────────────────────────────────
        $b = $this->seller('funnel-demo-black', 'Boutique Démo Audio', 'black');

        $p = $this->product($b, 'Écouteurs sans fil Air — réduction de bruit', 85, images: 5, short: true);
        $this->simulate($p, $b, ['imp' => 70, 'ctr' => 0.11, 'v2c' => 0.10, 'c2k' => 0.75, 'k2o' => 0.7, 'growth' => 0.25]);
        $this->reviews($b, $p, 4);

        // Seen a lot, rarely clicked: weak main photo
        $p2 = $this->product($b, 'Casque Studio X — son haute fidélité', 92, images: 5, short: true);
        $this->simulate($p2, $b, ['imp' => 150, 'ctr' => 0.02, 'v2c' => 0.09, 'c2k' => 0.7, 'k2o' => 0.65, 'direct' => 0.6]);
        $this->reviews($b, $p2, 2);

        // Views but few carts: price twice the category median
        $p3 = $this->product($b, 'Enceinte Boom 360 — étanche, 20 h d\'autonomie', 165, images: 5, short: true);
        $this->simulate($p3, $b, ['imp' => 80, 'ctr' => 0.10, 'v2c' => 0.012, 'c2k' => 0.6, 'k2o' => 0.6]);
        $this->reviews($b, $p3, 3);

        // Views but few carts: one photo, a one-line description
        $p4 = $this->product($b, 'Barre de son Slim', 74, images: 1, short: false, description: 'Barre de son.');
        $this->simulate($p4, $b, ['imp' => 75, 'ctr' => 0.09, 'v2c' => 0.015, 'c2k' => 0.6, 'k2o' => 0.6]);

        // Added to cart, not ordered: half the variants are out of stock
        $p5 = $this->product($b, 'Casque Gaming RGB — micro antibruit', 89, images: 5, short: true);
        foreach ([[12, 'NOIR'], [0, 'BLANC'], [0, 'ROUGE'], [6, 'BLEU']] as [$stock, $sku]) {
            DB::table('product_variants')->insert(['product_id' => $p5, 'sku' => "FD-RGB-$sku-" . Str::random(3), 'stock' => $stock,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->simulate($p5, $b, ['imp' => 70, 'ctr' => 0.10, 'v2c' => 0.13, 'c2k' => 0.25, 'k2o' => 0.5]);
        $this->reviews($b, $p5, 2);

        // Checkout started, not finished: delivery fee 2.5× the category's
        $p6 = $this->product($b, 'Enceinte Mini Pocket — Bluetooth 5.3', 59, images: 5, short: true, fee: 18);
        $this->simulate($p6, $b, ['imp' => 70, 'ctr' => 0.10, 'v2c' => 0.12, 'c2k' => 0.8, 'k2o' => 0.15]);
        $this->reviews($b, $p6, 1);

        // Nobody sees it (and its subcategory has no peers → compared to the category)
        $p7 = $this->product($b, 'Câble audio tressé jack 3,5 mm — 2 mètres', 25, images: 5, short: true, sub: $this->accessories);
        $this->simulate($p7, $b, ['imp' => 4, 'ctr' => 0.08, 'v2c' => 0.1, 'c2k' => 0.7, 'k2o' => 0.6, 'direct' => 0.05]);

        // Too new to judge
        $p8 = $this->product($b, 'Adaptateur Bluetooth récepteur voiture', 35, images: 4, short: true, listedDaysAgo: 5);
        $this->simulate($p8, $b, ['imp' => 12, 'ctr' => 0.1, 'v2c' => 0.1, 'c2k' => 0.6, 'k2o' => 0.6, 'days' => 5]);

        // Applied 18 days ago: a 15 % discount on a product that was barely converting
        $p9 = $this->product($b, 'Casque Kids — volume limité 85 dB', 69, images: 5, short: true);
        $this->simulate($p9, $b, ['imp' => 70, 'ctr' => 0.1, 'v2c' => 0.03, 'c2k' => 0.6, 'k2o' => 0.6, 'until' => 18]);
        $this->simulate($p9, $b, ['imp' => 75, 'ctr' => 0.11, 'v2c' => 0.10, 'c2k' => 0.7, 'k2o' => 0.65, 'days' => 18]);
        $this->reviews($b, $p9, 2);
        $start = $this->today->subDays(18)->setTime(9, 0);
        $promo = DB::table('promotions')->insertGetId([
            'seller_id' => $b, 'name' => 'Remise Analyse des visiteurs', 'type' => 'discount', 'discount_type' => 'percentage',
            'discount_value' => 15, 'status' => 'active', 'starts_at' => $start->utc(), 'ends_at' => $this->today->addDays(5)->utc(),
            'priority' => 5, 'created_at' => $start->utc(), 'updated_at' => now(),
        ]);
        DB::table('promotion_products')->insert(['promotion_id' => $promo, 'product_id' => $p9]);
        $this->action($b, $p9, 'discount', 'price_high', 'product_page', $promo, $start, $this->today->addDays(5));
        // Photos changed 3 days ago on the low-CTR product: still measuring
        $this->action($b, $p2, 'edit', 'low_ctr', 'click', null, $this->today->subDays(3)->setTime(11, 0));

        // The seller looking at their own products: logged, never counted
        foreach ([$p, $p3, $p5] as $own) {
            foreach (range(1, 6) as $d) $this->ui[] = $this->row($b, null, $own, $b, 'view', 'seller_store', 'desktop', $this->today->subDays($d)->setTime(10, 0));
        }

        // ── Low data and empty shops ──────────────────────────────────────────
        $n = $this->seller('funnel-demo-new', 'Boutique Démo Nouvelle', 'black');
        foreach (['Casque filaire basique — jack 3,5 mm', 'Enceinte de bureau USB'] as $name) {
            $q = $this->product($n, $name, 39, images: 2, listedDaysAgo: 12);
            $this->simulate($q, $n, ['imp' => 6, 'ctr' => 0.1, 'v2c' => 0.1, 'c2k' => 0.6, 'k2o' => 0.6, 'days' => 12]);
        }
        $this->seller('funnel-demo-empty', 'Boutique Démo Vide', 'black');

        $this->flush();

        // ── Roll up with the real pipeline ────────────────────────────────────
        $agg = app(FunnelAggregator::class);
        for ($d = self::DAYS; $d >= 0; $d--) $agg->aggregateDay($this->today->subDays($d));
        app(FunnelBenchmarks::class)->build($this->today->subDay());
        \Illuminate\Support\Facades\Cache::forget('funnel:tracking-since');
        foreach (DB::table('users')->where('email', 'like', self::EMAIL_LIKE)->where('role', 'seller')->pluck('id') as $sid) {
            \App\Services\VisitorInsights\VisitorInsights::forget((int) $sid);
        }

        $this->command?->info('Funnel demo ready — Black Pepper shops: funnel-demo-black / funnel-demo-new / funnel-demo-empty'
            . self::DOMAIN . '. Password: see FunnelDemoSeeder::PASSWORD.');
    }

    // ── Simulation ───────────────────────────────────────────────────────────

    /**
     * One product's traffic, day by day. $o: imp (impressions/day), ctr, v2c (view→cart),
     * c2k (cart→checkout), k2o (checkout→order), direct (extra direct/external views per
     * click-view, default 0.25), growth (traffic growth over the period), days / until
     * (simulate only the last `days` days, or only up to `until` days ago).
     */
    private function simulate(int $pid, int $sellerId, array $o): void
    {
        $days = $o['days'] ?? self::DAYS;
        $until = $o['until'] ?? 1;
        $sources = ['search' => 40, 'category' => 22, 'home' => 25, 'sponsored' => 5, 'storefront' => 8];
        $sections = ['search' => 'search', 'category' => 'category', 'home' => 'trending', 'sponsored' => 'sponsored_home', 'storefront' => 'seller_store'];
        for ($ago = $days; $ago >= $until; $ago--) {
            $day = $this->today->subDays($ago);
            $trend = 1 + ($o['growth'] ?? 0.1) * (1 - $ago / self::DAYS);
            $weekend = in_array((int) $day->format('N'), [5, 6, 7], true) ? 1.25 : 1;
            $imp = $this->poisson($o['imp'] * $trend * $weekend);
            for ($i = 0; $i < $imp; $i++) {
                $src = $this->pick($sources);
                $sid = (string) Str::uuid();
                $dev = $this->pick(['mobile' => 68, 'desktop' => 25, 'tablet' => 7]);
                $at = $day->setTime($this->hour(), mt_rand(0, 59), mt_rand(0, 59));
                $this->fe[] = ['product_id' => $pid, 'seller_id' => $sellerId, 'event' => 'impression', 'traffic_source' => $src,
                    'device' => $dev, 'user_id' => null, 'session_id' => $sid, 'ref' => null, 'excluded' => false, 'created_at' => $at->utc()];
                if ($this->chance($o['ctr'])) {
                    $this->ui[] = $this->row(null, $sid, $pid, $sellerId, 'click', $sections[$src], $dev, $at->addSeconds(5), $src);
                    $this->visit($pid, $sellerId, $sid, $dev, $src, $at->addSeconds(8), $o);
                }
            }
            // Visits that didn't come from a tracked listing: Google / social links, typed URLs
            $extra = $this->poisson($o['imp'] * $o['ctr'] * ($o['direct'] ?? 0.25) * $trend);
            for ($i = 0; $i < $extra; $i++) {
                $src = $this->chance(0.55) ? 'external' : 'direct';
                $dev = $this->pick(['mobile' => 75, 'desktop' => 20, 'tablet' => 5]);
                $this->visit($pid, $sellerId, (string) Str::uuid(), $dev, $src, $day->setTime($this->hour(), mt_rand(0, 59)), $o);
            }
        }
    }

    private function visit(int $pid, int $sellerId, string $sid, string $dev, string $src, CarbonImmutable $at, array $o): void
    {
        // A buyer who will reach checkout is logged in (orders need an account)
        $buyer = null;
        $carts = $this->chance($o['v2c']);
        $checkout = $carts && $this->chance($o['c2k']);
        if ($checkout) $buyer = $this->buyers[array_rand($this->buyers)];

        $this->ui[] = $this->row($buyer, $sid, $pid, $sellerId, 'view', $src, $dev, $at, $src);
        if ($this->chance(0.03)) $this->ui[] = $this->row($buyer, $sid, $pid, $sellerId, 'favorite_add', null, $dev, $at->addMinutes(1), $src);
        if (!$carts) return;
        $this->ui[] = $this->row($buyer, $sid, $pid, $sellerId, 'cart_add', null, $dev, $at->addMinutes(2), $src);
        if (!$checkout) return;
        $this->fe[] = ['product_id' => $pid, 'seller_id' => $sellerId, 'event' => 'checkout_start', 'traffic_source' => $src,
            'device' => $dev, 'user_id' => $buyer, 'session_id' => $sid, 'ref' => (string) Str::uuid(), 'excluded' => false,
            'created_at' => $at->addMinutes(4)->utc()];
        if ($this->chance($o['k2o'])) $this->order($sellerId, $pid, $buyer, $at->addMinutes(7));
    }

    private function row(?int $user, ?string $sid, int $pid, int $sellerId, string $type, ?string $section, string $dev, CarbonImmutable $at, ?string $src = null): array
    {
        return ['user_id' => $user, 'session_id' => $sid, 'product_id' => $pid, 'seller_id' => $sellerId, 'category_id' => $this->category,
            'event_type' => $type, 'source_section' => $section, 'traffic_source' => $src ?? ($section ? TrafficSource::fromSection($section) : null),
            'device' => $dev, 'funnel_excluded' => $user !== null && $user === $sellerId, 'created_at' => $at->utc()];
    }

    private function flush(): void
    {
        foreach (array_chunk($this->ui, 1000) as $chunk) DB::table('user_interactions')->insert($chunk);
        foreach (array_chunk($this->fe, 1000) as $chunk) DB::table('product_funnel_events')->insert($chunk);
        $this->ui = $this->fe = [];
    }

    private function order(int $sellerId, int $productId, int $buyer, CarbonImmutable $at): int
    {
        $p = DB::table('products')->where('id', $productId)->first(['price', 'delivery_fee']);
        $price = (float) $p->price;
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $buyer, 'order_number' => 'DEMO-FN-' . Str::upper(Str::random(10)),
            'total_amount' => $price, 'subtotal' => $price, 'status' => 'delivered',
            'payment_method' => 'cod', 'wilaya' => 'Tunis', 'created_at' => $at->utc(), 'updated_at' => $at->utc(),
        ]);
        $soId = DB::table('seller_orders')->insertGetId([
            'order_id' => $orderId, 'seller_id' => $sellerId, 'status' => 'delivered',
            'subtotal' => $price, 'created_at' => $at->utc(), 'updated_at' => $at->utc(),
        ]);
        return DB::table('order_items')->insertGetId([
            'order_id' => $orderId, 'seller_order_id' => $soId, 'product_id' => $productId, 'quantity' => 1,
            'unit_price' => $price, 'price' => $price, 'total' => $price, 'discount_amount' => 0, 'net_total' => $price,
            'created_at' => $at->utc(), 'updated_at' => $at->utc(),
        ]);
    }

    private function reviews(int $sellerId, int $productId, int $n): void
    {
        foreach (range(1, $n) as $i) {
            $buyer = $this->buyers[($productId + $i) % count($this->buyers)];
            $item = $this->order($sellerId, $productId, $buyer, $this->today->subDays(self::DAYS + 10 + $i)->setTime(12, 0));
            DB::table('reviews')->insert(['user_id' => $buyer, 'product_id' => $productId, 'order_item_id' => $item, 'seller_id' => $sellerId,
                'rating' => mt_rand(4, 5), 'body' => 'Très bon produit, livraison rapide.', 'is_verified_purchase' => true,
                'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function action(int $sellerId, int $productId, string $kind, string $problem, string $stage, ?int $ref,
                            CarbonImmutable $start, ?CarbonImmutable $end = null): void
    {
        DB::table('growth_actions')->insert([
            'seller_id' => $sellerId, 'origin' => 'visitor_insights', 'stage' => $stage, 'problem_code' => $problem,
            'product_id' => $productId, 'kind' => $kind, 'ref_id' => $ref, 'starts_at' => $start->utc(),
            'ends_at' => ($end ?? $start)->utc(), 'status' => 'running', 'created_at' => $start->utc(), 'updated_at' => now(),
        ]);
    }

    // ── Builders ─────────────────────────────────────────────────────────────

    private function subcategory(string $name, string $slug): int
    {
        return DB::table('subcategories')->insertGetId(['category_id' => $this->category, 'name' => $name, 'name_fr' => $name,
            'name_ar' => $name, 'slug' => $slug, 'is_active' => false, 'order' => 999, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $local, string $name, string $role): int
    {
        return DB::table('users')->insertGetId([
            'name' => $name, 'email' => $local . self::DOMAIN, 'password' => Hash::make(self::PASSWORD),
            'role' => $role, 'is_active' => true, 'is_approved' => true, 'onboarding_completed' => true,
            'locale' => 'fr', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seller(string $local, string $shop, string $plan): int
    {
        $id = $this->user($local, $shop, 'seller');
        $appId = DB::table('seller_applications')->insertGetId([
            'user_id' => $id, 'full_name' => $shop, 'phone_number' => '20000000', 'business_name' => $shop,
            'business_category' => 'other', 'wilaya' => 'Tunis', 'city' => 'Tunis', 'status' => 'approved',
            'plan' => $plan, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('seller_subscriptions')->insert([
            'seller_application_id' => $appId, 'user_id' => $id, 'current_plan' => $plan, 'status' => 'active',
            'billing_period' => 'monthly', 'billing_cycle_start' => $this->today->toDateString(),
            'billing_cycle_end' => $this->today->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function product(int $sellerId, string $name, float $price, int $images = 5, bool $short = true,
                             ?string $description = null, float $fee = 7, ?int $sub = null, int $listedDaysAgo = 120): int
    {
        $id = DB::table('products')->insertGetId([
            'seller_id' => $sellerId, 'category_id' => $this->category, 'subcategory_id' => $sub ?? $this->audio, 'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5)), 'price' => $price, 'stock' => 25, 'delivery_fee' => $fee,
            'description' => $description ?? 'Produit de démonstration. ' . str_repeat('Autonomie, connectique, contenu de la boîte et garantie détaillés. ', 4),
            'short_description' => $short ? 'Son clair, autonomie longue durée, garantie 1 an.' : null,
            'is_approved' => true, 'is_active' => true, 'views' => 0,
            'created_at' => $this->today->subDays($listedDaysAgo)->setTime(9, 0)->utc(), 'updated_at' => now(),
        ]);
        if ($this->image) {
            foreach (range(1, $images) as $i) {
                DB::table('product_images')->insert(['product_id' => $id, 'image_path' => $this->image, 'order' => $i,
                    'is_primary' => $i === 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        return $id;
    }

    private function pick(array $weights): string
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $k => $w) if (($r -= $w) <= 0) return $k;
        return array_key_first($weights);
    }

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    private function poisson(float $lambda): int
    {
        // Normal approximation is enough for a demo
        return max(0, (int) round($lambda + sqrt(max($lambda, 0.01)) * (mt_rand(-1000, 1000) / 1000)));
    }

    private function hour(): int
    {
        return mt_rand(1, 100) <= 45 ? mt_rand(19, 22) : mt_rand(9, 18);
    }
}
