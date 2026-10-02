<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Services\Forecast\Calendar;
use App\Services\Forecast\EventEffectMeasurer;
use App\Services\Forecast\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Realistic fake sales histories to try every forecast tier locally.
 *
 *   php artisan db:seed --class=ForecastDemoSeeder
 *   php artisan forecast:demo-purge            (removes everything it created)
 *
 * Log in to the seller dashboard as  forecast-demo@choosetounsi.test / demo-forecast-2026
 * (Red Pepper plan) and open Outils IA → Ventes.
 *
 * Everything lives in two hidden demo categories and belongs to users matching
 * forecast-%@choosetounsi.test, so it never mixes with real shops' data or priors.
 * Deterministic (fixed random seed).
 */
class ForecastDemoSeeder extends Seeder
{
    public const DOMAIN   = '@choosetounsi.test';
    /** Every account this seeder creates matches this LIKE pattern — and nothing else
     *  (the older DemoCatalog accounts share the @choosetounsi.test domain). */
    public const EMAIL_LIKE = 'forecast-%@choosetounsi.test';
    public const PASSWORD = 'demo-forecast-2026';

    private CarbonImmutable $today;
    private int $buyerId;
    private array $orderRows = [];

    public function run(): void
    {
        mt_srand(2026);
        $this->today = CarbonImmutable::now(config('forecast.timezone'))->startOfDay();
        if (DB::table('users')->where('email', 'forecast-demo' . self::DOMAIN)->exists()) {
            $this->command?->warn('Demo data already present — run php artisan forecast:demo-purge first.');
            return;
        }

        $catMode  = $this->category('Démo prévisions — Mode', 'demo-forecast-mode');
        $catEmpty = $this->category('Démo prévisions — Catégorie vide', 'demo-forecast-vide');
        $this->buyerId = $this->user('forecast-demo-buyer', 'Client Démo', 'client');

        // ── Peer shops: give the "Mode" category a measurable prior + a past event ─
        $lastYear = $this->today->addDays(30)->subYear();      // same event, one year earlier
        foreach (['A', 'B', 'C'] as $i => $letter) {
            $peer = $this->seller("forecast-peer-" . strtolower($letter), "Boutique pair $letter", 'free');
            foreach ([45, 70] as $k => $price) {
                $pid = $this->product($peer, $catMode, "Article pair $letter" . ($k + 1), $price, 60, 420);
                $this->history($peer, $pid, 400, function (int $ago, CarbonImmutable $d) use ($i, $k, $lastYear) {
                    $rate = 0.6 + 0.15 * $i + 0.1 * $k;
                    $inEvent = $d >= $lastYear && $d <= $lastYear->addDays(6);
                    return $this->poisson($inEvent ? $rate * 1.6 : $rate);
                });
            }
            $this->product($peer, $catMode, "Article pair $letter (sans vente)", 55, 20, 120);   // zero-sale peer counts too
        }

        // ── The demo shop ────────────────────────────────────────────────────
        $s = $this->seller('forecast-demo', 'Boutique Démo Prévisions', 'red');

        // 1. Category estimate: no sale yet, similar products exist
        $this->product($s, $catMode, 'Nouvelle robe (aucune vente)', 60, 15, 5);

        // 2. Not enough data at all: empty category, no sale
        $this->product($s, $catEmpty, 'Produit sans données', 35, 10, 20);

        // 3. Blend: 3 orders in 50 days
        $p = $this->product($s, $catMode, 'Chemise lin (quelques ventes)', 55, 12, 50);
        foreach ([4, 19, 37] as $ago) $this->order($s, $p, $ago, 1);

        // 4. Intermittent demand (Croston/SBA)
        $p = $this->product($s, $catMode, 'Sac en cuir (ventes irrégulières)', 120, 9, 300);
        $this->history($s, $p, 300, fn($ago) => mt_rand(1, 100) <= 11 ? mt_rand(1, 2) : 0);

        // 5. Steady best-seller about to run out (+ cancelled orders that must not count)
        $p = $this->product($s, $catMode, 'T-shirt basique (best-seller)', 39, 25, 365);
        $this->history($s, $p, 365, fn() => $this->poisson(2.2));
        foreach ([3, 6, 9, 12] as $ago) $this->order($s, $p, $ago, 5, 'cancelled');
        foreach ([1, 2] as $ago) $this->order($s, $p, $ago, 4, 'pending');

        // 6. Seasonal winter product, 2+ years of history
        $p = $this->product($s, $catMode, 'Pull en laine (saisonnier)', 85, 40, 800);
        $this->history($s, $p, 800, fn($ago, $d) => $this->poisson(0.25 + 1.6 * max(0, cos(2 * M_PI * ((int) $d->format('z') - 10) / 365.25))));

        // 7. Promo spike: steady sales + a flash sale 40–46 days ago
        $p = $this->product($s, $catMode, 'Jean droit (flash sale passée)', 75, 30, 200);
        $promoId = DB::table('promotions')->insertGetId([
            'seller_id' => $s, 'name' => 'Flash démo', 'type' => 'flash_sale', 'discount_type' => 'percentage',
            'discount_value' => 30, 'status' => 'expired',
            'starts_at' => $this->today->subDays(46)->utc(), 'ends_at' => $this->today->subDays(40)->endOfDay()->utc(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('promotion_products')->insert(['promotion_id' => $promoId, 'product_id' => $p]);
        $this->history($s, $p, 200, function ($ago) use ($s, $p, $promoId) {
            if ($ago >= 40 && $ago <= 46) {
                $this->order($s, $p, $ago, mt_rand(5, 9), 'delivered', $promoId);
                return 0;
            }
            return $this->poisson(0.9);
        });

        // 8. Sizes: M sells most and is almost out
        $p = $this->product($s, $catMode, 'Polo (tailles S/M/L)', 49, 0, 240);
        $sizes = DB::table('attribute_options as o')->join('attributes as a', 'a.id', '=', 'o.attribute_id')
            ->where('a.slug', 'size')->whereIn('o.value', ['S', 'M', 'L'])->pluck('o.id', 'o.value');
        $variants = [];
        foreach (['S' => 18, 'M' => 4, 'L' => 14] as $size => $stock) {
            $vid = DB::table('product_variants')->insertGetId(['product_id' => $p, 'stock' => $stock, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            if (isset($sizes[$size])) {
                DB::table('variant_attribute_values')->insert(['variant_id' => $vid, 'attribute_option_id' => $sizes[$size], 'created_at' => now(), 'updated_at' => now()]);
            }
            $variants[$size] = $vid;
        }
        $this->history($s, $p, 240, function ($ago) use ($s, $p, $variants) {
            $r = mt_rand(1, 100);
            $size = $r <= 20 ? 'S' : ($r <= 75 ? 'M' : 'L');
            if (mt_rand(1, 100) <= 70) $this->order($s, $p, $ago, 1, 'delivered', null, $variants[$size]);
            return 0;
        });

        // 9. Dormant stock: one sale 100 days ago, 30 in stock
        $p = $this->product($s, $catMode, 'Veste habillée (stock dormant)', 140, 30, 130);
        $this->order($s, $p, 100, 1);

        // 10. Many views, few add-to-carts
        $p = $this->product($s, $catMode, 'Robe de soirée (beaucoup de vues)', 180, 20, 150);
        foreach ([12, 55, 97] as $ago) $this->order($s, $p, $ago, 1);
        $this->interactions($s, $p, $catMode, 30, 9, 0.01);

        $this->flushOrders();

        // ── Calendar: computed Islamic holidays + a demo event in ~4 weeks with a
        //    measured effect from last year's occurrence ─────────────────────────
        Calendar::seedHijriYear((int) $this->today->format('Y'));
        Calendar::seedHijriYear((int) $this->today->format('Y') + 1);
        $past = CalendarEvent::create([
            'key' => 'demo_event', 'name_fr' => '[Démo] Fête test', 'name_en' => '[Demo] Test holiday', 'name_ar' => '[تجربة] عيد تجريبي',
            'starts_on' => $lastYear->toDateString(), 'ends_on' => $lastYear->addDays(6)->toDateString(),
            'category_ids' => null, 'is_active' => true, 'source' => 'admin',
        ]);
        CalendarEvent::create([
            'key' => 'demo_event', 'name_fr' => '[Démo] Fête test', 'name_en' => '[Demo] Test holiday', 'name_ar' => '[تجربة] عيد تجريبي',
            'starts_on' => $this->today->addDays(30)->toDateString(), 'ends_on' => $this->today->addDays(36)->toDateString(),
            'category_ids' => null, 'is_active' => true, 'source' => 'admin',
        ]);
        app(EventEffectMeasurer::class)->measure($past);

        // ── Compute so the dashboard is ready ──────────────────────────────────
        $service = app(ForecastService::class);
        foreach (DB::table('users')->where('email', 'like', self::EMAIL_LIKE)->where('role', 'seller')->pluck('id') as $sid) {
            $service->computeSeller((int) $sid);
        }
        $this->command?->info('Forecast demo ready — log in as forecast-demo' . self::DOMAIN . ' / ' . self::PASSWORD);
    }

    // ── Builders ─────────────────────────────────────────────────────────────

    private function category(string $name, string $slug): int
    {
        return DB::table('categories')->insertGetId([
            'name' => $name, 'name_fr' => $name, 'name_ar' => $name, 'slug' => $slug . '-' . Str::lower(Str::random(4)),
            'is_active' => false, 'order' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
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

    private function product(int $sellerId, int $categoryId, string $name, float $price, int $stock, int $listedDaysAgo): int
    {
        return DB::table('products')->insertGetId([
            'seller_id' => $sellerId, 'category_id' => $categoryId, 'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5)), 'price' => $price, 'stock' => $stock,
            'is_approved' => true, 'is_active' => true, 'views' => 0,
            'created_at' => $this->today->subDays($listedDaysAgo)->setTime(9, 0)->utc(), 'updated_at' => now(),
        ]);
    }

    /** One order per day with the units $fn returns (0 = no order that day). */
    private function history(int $sellerId, int $productId, int $days, callable $fn): void
    {
        for ($ago = $days; $ago >= 1; $ago--) {
            $u = (int) $fn($ago, $this->today->subDays($ago));
            if ($u > 0) $this->order($sellerId, $productId, $ago, $u);
        }
    }

    private function order(int $sellerId, int $productId, int $daysAgo, int $qty, string $status = 'delivered', ?int $promotionId = null, ?int $variantId = null): void
    {
        $price = (float) DB::table('products')->where('id', $productId)->value('price');
        $net   = $promotionId ? round($price * 0.7, 3) : $price;
        $this->orderRows[] = compact('sellerId', 'productId', 'daysAgo', 'qty', 'status', 'promotionId', 'variantId', 'price', 'net');
        if (count($this->orderRows) >= 300) $this->flushOrders();
    }

    private function flushOrders(): void
    {
        foreach ($this->orderRows as $o) {
            $at = $this->today->subDays($o['daysAgo'])->setTime(mt_rand(8, 21), mt_rand(0, 59))->utc();
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $this->buyerId, 'order_number' => 'DEMO-FC-' . Str::upper(Str::random(10)),
                'total_amount' => $o['net'] * $o['qty'], 'subtotal' => $o['net'] * $o['qty'], 'status' => $o['status'],
                'payment_method' => 'cod', 'wilaya' => 'Tunis', 'created_at' => $at, 'updated_at' => $at,
            ]);
            $soId = DB::table('seller_orders')->insertGetId([
                'order_id' => $orderId, 'seller_id' => $o['sellerId'], 'status' => $o['status'],
                'subtotal' => $o['net'] * $o['qty'], 'created_at' => $at, 'updated_at' => $at,
            ]);
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'seller_order_id' => $soId, 'product_id' => $o['productId'], 'variant_id' => $o['variantId'],
                'promotion_id' => $o['promotionId'], 'quantity' => $o['qty'], 'unit_price' => $o['net'], 'price' => $o['price'],
                'total' => $o['price'] * $o['qty'], 'discount_amount' => ($o['price'] - $o['net']) * $o['qty'],
                'net_total' => $o['net'] * $o['qty'], 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        $this->orderRows = [];
    }

    private function interactions(int $sellerId, int $productId, int $categoryId, int $days, int $viewsPerDay, float $cartRate): void
    {
        $rows = [];
        for ($ago = $days; $ago >= 1; $ago--) {
            for ($v = 0; $v < $viewsPerDay + mt_rand(-2, 3); $v++) {
                $at = $this->today->subDays($ago)->setTime(mt_rand(8, 22), mt_rand(0, 59))->utc();
                $base = ['user_id' => null, 'session_id' => (string) Str::uuid(), 'product_id' => $productId,
                         'seller_id' => $sellerId, 'category_id' => $categoryId, 'source_section' => 'demo', 'created_at' => $at];
                $rows[] = $base + ['event_type' => 'view'];
                if (mt_rand() / mt_getrandmax() < $cartRate) $rows[] = $base + ['event_type' => 'cart_add'];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) DB::table('user_interactions')->insert($chunk);
    }

    private function poisson(float $lambda): int
    {
        $l = exp(-$lambda); $k = 0; $p = 1.0;
        do { $k++; $p *= mt_rand() / mt_getrandmax(); } while ($p > $l);
        return $k - 1;
    }
}
