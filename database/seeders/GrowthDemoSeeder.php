<?php

namespace Database\Seeders;

use App\Services\GrowthRadar\GrowthRadar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Growth Radar demo: php artisan growth:demo   (remove: php artisan growth:demo --purge)
 *
 * One Black Pepper shop where every card type fires from real-looking data, five
 * peer shops so the category passes the privacy floor (≥5 shops), interested
 * buyers for a targeted coupon, missed searches, a calendar moment in 3 weeks
 * with an effect measured last year, a finished promotion applied from a card
 * (its result is measured by growth:measure), plus a Red and a Green shop to
 * see the locked view.
 *
 * Everything it creates matches EMAIL_LIKE / the demo-growth-% category slug,
 * and nothing else (the older DemoCatalog accounts share the domain).
 */
class GrowthDemoSeeder extends Seeder
{
    public const DOMAIN     = '@choosetounsi.test';
    public const EMAIL_LIKE = 'growth-%@choosetounsi.test';
    public const CATEGORY_LIKE = 'demo-growth-%';
    public const EVENT_KEY  = 'growth_demo_event';
    public const PASSWORD   = 'demo-growth-2026';

    private CarbonImmutable $today;
    private int $category;
    private ?string $image;
    private array $buyers = [];

    public function run(): void
    {
        mt_srand(2027);
        $this->today = GrowthRadar::today();
        if (DB::table('users')->where('email', 'growth-demo' . self::DOMAIN)->exists()) {
            $this->command?->warn('Growth demo already present — run php artisan growth:demo --purge first (or growth:demo --fresh).');
            return;
        }
        $this->image = DB::table('product_images')->where('image_path', 'like', 'products/demo/%')->value('image_path');
        $this->category = DB::table('categories')->insertGetId([
            'name' => 'Démo Growth — Mode', 'name_fr' => 'Démo Growth — Mode', 'name_ar' => 'Démo Growth — Mode',
            'slug' => 'demo-growth-mode-' . Str::lower(Str::random(4)), 'is_active' => false, 'order' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(1, 20) as $i) $this->buyers[] = $this->user("growth-buyer-$i", "Client Démo $i", 'client');

        // ── Five peer shops: a category price range of ~40–60 DT ───────────────
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $letter) {
            $peer = $this->seller("growth-peer-$letter", 'Boutique pair ' . strtoupper($letter), 'free');
            foreach ([40 + $i * 2, 52 + $i * 2] as $k => $price) {
                $p = $this->product($peer, "Article pair " . strtoupper($letter) . ($k + 1), $price, 30, 120);
                $this->views($p, $peer, 30, 4);
                foreach ([3, 9, 16, 24] as $ago) $this->order($peer, $p, $ago, 1);
            }
        }

        // ── The demo shop (Black Pepper) ──────────────────────────────────────
        $s = $this->seller('growth-demo', 'Boutique Démo Growth', 'black');

        // Price position (high): far above the range, seen but not bought
        $p = $this->product($s, 'Robe brodée premium', 140, 12, 90);
        $this->views($p, $s, 30, 3);

        // Leaking product: many views, one photo only
        $p = $this->product($s, 'Sac cabas tressé', 49, 20, 90, images: 1);
        $this->views($p, $s, 30, 6);
        $this->order($s, $p, 12, 1);

        // Dead stock: 25 units, last sale 80 days ago, still seen
        $p = $this->product($s, 'Veste en jean (stock dormant)', 55, 25, 160);
        $this->order($s, $p, 80, 1);
        $this->views($p, $s, 30, 1.5);

        // Warm audience: favourited / left in cart by 16 buyers
        $p = $this->product($s, 'Foulard en soie', 45, 15, 30);
        $this->views($p, $s, 30, 2);
        foreach (array_slice($this->buyers, 0, 12) as $b) {
            DB::table('favorites')->insert(['user_id' => $b, 'product_id' => $p, 'created_at' => now()->subDays(mt_rand(2, 20)), 'updated_at' => now()]);
        }
        foreach (array_slice($this->buyers, 12, 4) as $b) {
            $this->interaction($b, $p, $s, 'cart_add', $this->today->subDays(mt_rand(2, 10))->setTime(20, 15));
        }

        // Price position (low): sells well far under the range
        $p = $this->product($s, 'T-shirt coton bio', 29, 60, 120);
        $this->views($p, $s, 30, 5);
        foreach (range(1, 28) as $ago) if ($ago % 2 === 0) $this->order($s, $p, $ago, 1);

        // Applied card in the past: a 7-day discount that ended 10 days ago (growth:measure → result)
        $p = $this->product($s, 'Chemise en lin', 50, 40, 150);
        $start = $this->today->subDays(17)->setTime(9, 0);
        $end = $start->addDays(7);
        $promo = DB::table('promotions')->insertGetId([
            'seller_id' => $s, 'name' => 'Remise Growth Radar', 'type' => 'discount', 'discount_type' => 'percentage',
            'discount_value' => 15, 'status' => 'expired', 'starts_at' => $start->utc(), 'ends_at' => $end->utc(),
            'priority' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('promotion_products')->insert(['promotion_id' => $promo, 'product_id' => $p]);
        foreach ([24, 22, 19] as $ago) $this->order($s, $p, $ago, 1);                    // before: 3 units
        foreach ([16, 15, 15, 14, 13, 12, 12, 11, 11, 10] as $ago) $this->order($s, $p, $ago, 1, $promo, 0.85);  // during: 10
        foreach ([8, 5, 2] as $ago) $this->order($s, $p, $ago, 1);                        // after: 3
        $this->views($p, $s, 30, 3);
        $card = DB::table('growth_cards')->insertGetId([
            'seller_id' => $s, 'week_start' => GrowthRadar::weekStart($start)->toDateString(), 'type' => 'leaking_product',
            'fingerprint' => "leak:$p", 'product_id' => $p, 'status' => 'applied', 'confidence' => 'medium',
            'impact_low' => 40, 'impact_high' => 90, 'rank' => 1000065, 'payload' => json_encode(['params' => ['product' => 'Chemise en lin']]),
            'applied_at' => $start->utc(), 'created_at' => $start->subDay()->utc(), 'updated_at' => now(),
        ]);
        DB::table('growth_actions')->insert([
            'seller_id' => $s, 'card_id' => $card, 'card_type' => 'leaking_product', 'product_id' => $p, 'kind' => 'discount',
            'ref_id' => $promo, 'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'status' => 'running',
            'created_at' => $start->utc(), 'updated_at' => now(),
        ]);

        // ── Missed searches in the demo category ──────────────────────────────
        foreach ([['caftan brode', 'caftan brodé', 46], ['jebba homme', 'jebba homme', 33], ['robe kabyle', 'robe kabyle', 12]] as [$q, $typed, $n]) {
            DB::table('search_missed_queries')->insert([
                'query' => $q, 'day' => $this->today->subDays(3)->toDateString(), 'searches' => $n, 'results' => 0,
                'example' => $typed, 'category_id' => $this->category, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ── Calendar: a moment in 3 weeks with an effect measured last year ───
        $past = DB::table('calendar_events')->insertGetId([
            'key' => self::EVENT_KEY, 'name_fr' => '[Démo] Fête des tissus', 'name_en' => '[Demo] Fabric fair', 'name_ar' => '[تجربة] معرض الأقمشة',
            'starts_on' => $this->today->addDays(21)->subYear()->toDateString(), 'ends_on' => $this->today->addDays(27)->subYear()->toDateString(),
            'category_ids' => json_encode([$this->category]), 'is_active' => true, 'source' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('calendar_event_effects')->insert([
            'calendar_event_id' => $past, 'category_id' => $this->category, 'event_daily_units' => 6.2, 'baseline_daily_units' => 4.6,
            'change_pct' => 35, 'event_orders' => 43, 'baseline_orders' => 128, 'event_days' => 7, 'baseline_days' => 56,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('calendar_events')->insert([
            'key' => self::EVENT_KEY, 'name_fr' => '[Démo] Fête des tissus', 'name_en' => '[Demo] Fabric fair', 'name_ar' => '[تجربة] معرض الأقمشة',
            'starts_on' => $this->today->addDays(21)->toDateString(), 'ends_on' => $this->today->addDays(27)->toDateString(),
            'category_ids' => json_encode([$this->category]), 'is_active' => true, 'source' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── Red and Green shops (locked view) ─────────────────────────────────
        foreach (['red' => 'growth-red', 'free' => 'growth-green'] as $plan => $local) {
            $x = $this->seller($local, 'Boutique Démo ' . ($plan === 'free' ? 'Green' : 'Red'), $plan);
            $p = $this->product($x, "Robe longue ($plan)", 130, 10, 60, images: 1);
            $this->views($p, $x, 30, 3);
            $this->product($x, "Jupe plissée ($plan)", 48, 8, 20);
        }

        // ── Compute (no notifications: demo inboxes) ──────────────────────────
        $radar = app(GrowthRadar::class);
        foreach (DB::table('users')->where('email', 'like', self::EMAIL_LIKE)->where('role', 'seller')->pluck('id') as $sid) {
            $radar->compute((int) $sid, null, false);
        }
        $this->command?->info('Growth demo ready — Black Pepper shop: growth-demo' . self::DOMAIN
            . ' (also growth-red / growth-green). Password: see GrowthDemoSeeder::PASSWORD.');
    }

    // ── Builders ─────────────────────────────────────────────────────────────

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

    private function product(int $sellerId, string $name, float $price, int $stock, int $listedDaysAgo, int $images = 3): int
    {
        $id = DB::table('products')->insertGetId([
            'seller_id' => $sellerId, 'category_id' => $this->category, 'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5)), 'price' => $price, 'stock' => $stock,
            'description' => 'Produit de démonstration Growth Radar. ' . str_repeat('Matière, tailles, entretien et livraison détaillés. ', 6),
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

    /**
     * About $perDay guest views a day over $days days. Thursday evening gets the most
     * traffic, so the platform pattern has a clear best slot.
     */
    private function views(int $productId, int $sellerId, int $days, float $perDay): void
    {
        $rows = [];
        for ($ago = $days; $ago >= 1; $ago--) {
            $d = $this->today->subDays($ago);
            $n = (int) round($perDay * ((int) $d->format('N') === 4 ? 2.2 : 1) + mt_rand(-1, 1));
            for ($v = 0; $v < max(0, $n); $v++) {
                $hour = mt_rand(1, 100) <= 45 ? mt_rand(19, 22) : mt_rand(9, 18);
                $rows[] = ['user_id' => null, 'session_id' => (string) Str::uuid(), 'product_id' => $productId, 'seller_id' => $sellerId,
                    'category_id' => $this->category, 'event_type' => 'view', 'source_section' => 'demo',
                    'created_at' => $d->setTime($hour, mt_rand(0, 59))->utc()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) DB::table('user_interactions')->insert($chunk);
    }

    private function interaction(int $userId, int $productId, int $sellerId, string $type, CarbonImmutable $at): void
    {
        DB::table('user_interactions')->insert(['user_id' => $userId, 'session_id' => (string) Str::uuid(), 'product_id' => $productId,
            'seller_id' => $sellerId, 'category_id' => $this->category, 'event_type' => $type, 'source_section' => 'demo', 'created_at' => $at->utc()]);
    }

    private function order(int $sellerId, int $productId, int $daysAgo, int $qty, ?int $promotionId = null, float $factor = 1.0): void
    {
        $price = (float) DB::table('products')->where('id', $productId)->value('price');
        $net = round($price * $factor, 3);
        $at = $this->today->subDays($daysAgo)->setTime(mt_rand(9, 21), mt_rand(0, 59))->utc();
        $buyer = $this->buyers[array_rand($this->buyers)];
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $buyer, 'order_number' => 'DEMO-GR-' . Str::upper(Str::random(10)),
            'total_amount' => $net * $qty, 'subtotal' => $net * $qty, 'status' => 'delivered',
            'payment_method' => 'cod', 'wilaya' => 'Tunis', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $soId = DB::table('seller_orders')->insertGetId([
            'order_id' => $orderId, 'seller_id' => $sellerId, 'status' => 'delivered',
            'subtotal' => $net * $qty, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'seller_order_id' => $soId, 'product_id' => $productId, 'promotion_id' => $promotionId,
            'quantity' => $qty, 'unit_price' => $net, 'price' => $price, 'total' => $price * $qty,
            'discount_amount' => ($price - $net) * $qty, 'net_total' => $net * $qty, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('user_interactions')->insert(['user_id' => $buyer, 'session_id' => (string) Str::uuid(), 'product_id' => $productId,
            'seller_id' => $sellerId, 'category_id' => $this->category, 'event_type' => 'purchase', 'source_section' => 'demo',
            'order_id' => $orderId, 'created_at' => $at]);
    }
}
