<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ✅ Delegate admin creation to AdminSeeder — no duplication
        $this->call(AdminSeeder::class);
        $this->call([
        CategorySeeder::class,]);
        // ── Sellers ───────────────────────────────────────────────
        $approvedSeller = User::updateOrCreate(
            ['email' => 'seller1@example.com'],
            [
                'name'        => 'Mohamed Ben Ali',
                'password'    => Hash::make('password'),
                'role'        => 'seller',
                'is_active'   => true,
                'is_approved' => true,
            ]
        );

        $pendingSeller1 = User::updateOrCreate(
            ['email' => 'seller2@example.com'],
            [
                'name'        => 'Fatma Trabelsi',
                'password'    => Hash::make('password'),
                'role'        => 'seller',
                'is_active'   => true,
                'is_approved' => false,
            ]
        );

        $pendingSeller2 = User::updateOrCreate(
            ['email' => 'seller3@example.com'],
            [
                'name'        => 'Karim Bouazizi',
                'password'    => Hash::make('password'),
                'role'        => 'seller',
                'is_active'   => true,
                'is_approved' => false,
            ]
        );

        $suspendedSeller = User::updateOrCreate(
            ['email' => 'seller4@example.com'],
            [
                'name'        => 'Leila Mansouri',
                'password'    => Hash::make('password'),
                'role'        => 'seller',
                'is_active'   => false,
                'is_approved' => true,
            ]
        );
        echo "✓ Sellers created (1 approved, 2 pending, 1 suspended)\n";

        // ── Clients ───────────────────────────────────────────────
        $client1 = User::updateOrCreate(
            ['email' => 'client1@example.com'],
            [
                'name'        => 'Ahmed Gharbi',
                'password'    => Hash::make('password'),
                'role'        => 'client',
                'is_active'   => true,
                'is_approved' => true,
            ]
        );

        $client2 = User::updateOrCreate(
            ['email' => 'client2@example.com'],
            [
                'name'        => 'Sana Khelifi',
                'password'    => Hash::make('password'),
                'role'        => 'client',
                'is_active'   => true,
                'is_approved' => true,
            ]
        );

        $client3 = User::updateOrCreate(
            ['email' => 'client3@example.com'],
            [
                'name'        => 'Rami Jebali',
                'password'    => Hash::make('password'),
                'role'        => 'client',
                'is_active'   => false,
                'is_approved' => true,
            ]
        );
        echo "✓ Clients created (2 active, 1 banned)\n";

        // ── Get a category id safely ──────────────────────────────
        $categoryId = DB::table('categories')->value('id');

        // ── Products ──────────────────────────────────────────────
        Product::updateOrCreate(['sku' => 'TN-OIL-001'], [
            'seller_id'   => $approvedSeller->id,
            'category_id' => $categoryId,
            'name'        => 'Tunisian Olive Oil - Premium',
            'description' => 'Extra virgin olive oil from Tunisian groves',
            'price'       => 25.990,
            'stock'       => 100,
            'is_approved' => true,
            'is_active'   => true,
        ]);

        Product::updateOrCreate(['sku' => 'TN-POT-001'], [
            'seller_id'   => $approvedSeller->id,
            'category_id' => $categoryId,
            'name'        => 'Handmade Tunisian Pottery',
            'description' => 'Traditional ceramic pottery',
            'price'       => 45.000,
            'stock'       => 25,
            'is_approved' => true,
            'is_active'   => true,
        ]);

        Product::updateOrCreate(['sku' => 'TN-DATE-001'], [
            'seller_id'   => $approvedSeller->id,
            'category_id' => $categoryId,
            'name'        => 'Tunisian Dates - Deglet Nour',
            'description' => 'Fresh Deglet Nour dates',
            'price'       => 15.990,
            'stock'       => 200,
            'is_approved' => false,
            'is_active'   => false,
        ]);

        Product::updateOrCreate(['sku' => 'TN-HAR-001'], [
            'seller_id'   => $pendingSeller1->id,
            'category_id' => $categoryId,
            'name'        => 'Tunisian Harissa Paste',
            'description' => 'Authentic spicy harissa paste',
            'price'       => 8.990,
            'stock'       => 150,
            'is_approved' => false,
            'is_active'   => false,
        ]);

        Product::updateOrCreate(['sku' => 'TN-SPI-001'], [
            'seller_id'   => $approvedSeller->id,
            'category_id' => $categoryId,
            'name'        => 'Tunisian Spice Mix',
            'description' => 'Traditional spice blend',
            'price'       => 12.990,
            'stock'       => 75,
            'is_approved' => true,
            'is_active'   => false,
        ]);
        echo "✓ Products created (2 approved, 2 pending, 1 disabled)\n";

        // ── Orders ────────────────────────────────────────────────
        // Check your ENUM first and use only valid values
        $orders = [
            [
                'user_id'        => $client1->id,
                'order_number'   => 'ORD-2024-001',
                'total_amount'   => 71.980,
                'status'         => 'completed',
                'payment_status' => 'paid',
                'payment_method' => 'credit_card',
            ],
            [
                'user_id'        => $client2->id,
                'order_number'   => 'ORD-2024-002',
                'total_amount'   => 25.990,
                'status'         => 'pending',
                'payment_status' => 'unpaid',
                'payment_method' => 'cash_on_delivery',
            ],
            [
                'user_id'        => $client1->id,
                'order_number'   => 'ORD-2024-003',
                'total_amount'   => 45.000,
                'status'         => 'processing',
                'payment_status' => 'paid',
                'payment_method' => 'credit_card',
            ],
            [
                'user_id'        => $client2->id,
                'order_number'   => 'ORD-2024-004',
                'total_amount'   => 33.980,
                'status'         => 'completed',
                'payment_status' => 'paid',
                'payment_method' => 'credit_card',
            ],
            [
                'user_id'        => $client3->id,
                'order_number'   => 'ORD-2024-005',
                'total_amount'   => 15.990,
                'status'         => 'cancelled',
                'payment_status' => 'refunded',
                'payment_method' => 'credit_card',
            ],
        ];

        foreach ($orders as $order) {
            Order::updateOrCreate(
                ['order_number' => $order['order_number']],
                $order
            );
        }
        echo "✓ Orders created\n";

        echo "\n==============================================\n";
        echo "✅ Database seeded successfully!\n";
        echo "==============================================\n";
        echo "Admin Login:\n";
        echo "  Email:    admin@choosetounsi.com\n";
        echo "  Password: Admin@1234!\n";      
        echo "==============================================\n";
    }
}