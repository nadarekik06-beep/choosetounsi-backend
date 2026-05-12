<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * AlertTestSeeder
 *
 * Creates backdated products with fake sales data to test alert scenarios.
 *
 * Usage:
 *   php artisan db:seed --class=AlertTestSeeder --seller-id=1
 *
 * Or use the dedicated command:
 *   php artisan ct:seed-alert-tests --seller-id=1
 */
class AlertTestSeeder extends Seeder
{
    public function run(int $sellerId = 1): void
    {
        $categoryId = DB::table('categories')->value('id') ?? 1;

        $scenarios = [
            [
                'name'         => '[TEST] Zero Sales – Critical Alert',
                'price'        => 89.900,
                'stock'        => 50,
                'views'        => 12,
                'created_days_ago' => 25,
                'units_sold'   => 0,
                'expect_alert' => 'critical',
            ],
            [
                'name'         => '[TEST] Low Sales – Warning Alert',
                'price'        => 45.000,
                'stock'        => 30,
                'views'        => 80,
                'created_days_ago' => 22,
                'units_sold'   => 1,
                'expect_alert' => 'warning',
            ],
            [
                'name'         => '[TEST] High Stock Ratio – Warning',
                'price'        => 120.000,
                'stock'        => 200,
                'views'        => 300,
                'created_days_ago' => 30,
                'units_sold'   => 2,
                'expect_alert' => 'warning',
            ],
            [
                'name'         => '[TEST] Good Product – No Alert',
                'price'        => 75.000,
                'stock'        => 15,
                'views'        => 500,
                'created_days_ago' => 25,
                'units_sold'   => 20,
                'expect_alert' => 'none',
            ],
            [
                'name'         => '[TEST] Too New – No Alert',
                'price'        => 55.000,
                'stock'        => 40,
                'views'        => 5,
                'created_days_ago' => 5,   // under 20-day threshold
                'units_sold'   => 0,
                'expect_alert' => 'none',
            ],
        ];

        foreach ($scenarios as $s) {
            $createdAt = Carbon::now()->subDays($s['created_days_ago']);

            // Create the product with backdated timestamp
            $productId = DB::table('products')->insertGetId([
                'seller_id'   => $sellerId,
                'category_id' => $categoryId,
                'name'        => $s['name'],
                'slug'        => \Illuminate\Support\Str::slug($s['name']) . '-' . uniqid(),
                'price'       => $s['price'],
                'stock'       => $s['stock'],
                'views'       => $s['views'],
                'is_approved' => true,
                'is_active'   => true,
                'created_at'  => $createdAt,
                'updated_at'  => $createdAt,
            ]);

            // Create fake orders within the alert window
            if ($s['units_sold'] > 0) {
                $orderId = DB::table('orders')->insertGetId([
                    'user_id'        => 1,
                    'order_number'   => 'TEST-' . uniqid(),
                    'total_amount'   => $s['price'] * $s['units_sold'],
                    'status'         => 'completed',
                    'payment_status' => 'paid',
                    'created_at'     => Carbon::now()->subDays(5),
                    'updated_at'     => Carbon::now()->subDays(5),
                ]);

                DB::table('order_items')->insert([
                    'order_id'   => $orderId,
                    'product_id' => $productId,
                    'quantity'   => $s['units_sold'],
                    'unit_price' => $s['price'],
                    'price'      => $s['price'],
                    'total'      => $s['price'] * $s['units_sold'],
                    'created_at' => Carbon::now()->subDays(5),
                    'updated_at' => Carbon::now()->subDays(5),
                ]);
            }

            $this->command->info("Created: {$s['name']} → expect: {$s['expect_alert']}");
        }

        $this->command->info('Done. Visit /seller/products to see alerts.');
        $this->command->info('Use ?alert_debug=1 in API calls to trigger all thresholds.');
    }
}