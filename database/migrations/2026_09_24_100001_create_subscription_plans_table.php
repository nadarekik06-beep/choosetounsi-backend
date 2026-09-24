<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed subscription plans + platform settings.
 *
 * Seeded with the values that were previously hardcoded in
 * SellerSubscription / CommissionService, so behaviour is unchanged until
 * an admin edits something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();          // stored on seller_subscriptions.current_plan
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->string('badge_color', 20)->default('#198f41');
            $table->unsignedSmallInteger('display_order')->default(0);
            // Seller-dashboard experience + sponsorship pricing level: 0 green, 1 red, 2 black
            $table->unsignedTinyInteger('tier')->default(0);

            $table->decimal('price_monthly', 10, 3)->default(0);
            $table->decimal('price_yearly', 10, 3)->nullable();   // null = no yearly billing
            $table->unsignedSmallInteger('trial_days')->default(0);

            // Commission: flat rate wins; otherwise platform tiers minus reduction
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('commission_reduction', 5, 2)->default(0);

            // Limits (null = unlimited)
            $table->unsignedInteger('max_products')->nullable();
            $table->unsignedSmallInteger('max_images_per_product')->nullable();
            $table->unsignedSmallInteger('max_sponsored_products')->nullable();

            $table->json('features');                      // { analytics: bool, ai_tools: bool, ... }

            $table->boolean('is_active')->default(true);   // offered to sellers
            $table->boolean('is_default')->default(false); // fallback plan on expiry / cancel
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        $base = ['promotions' => true, 'coupons' => true, 'sponsorships' => true];
        DB::table('subscription_plans')->insert([
            [
                'slug' => 'free', 'name' => 'Green Pepper', 'badge_color' => '#198f41', 'display_order' => 1, 'tier' => 0,
                'description' => 'Free plan to start selling on Choose\'Tounsi.',
                'price_monthly' => 0, 'price_yearly' => null, 'trial_days' => 0,
                'commission_rate' => null, 'commission_reduction' => 0,
                'max_products' => 30, 'max_images_per_product' => null, 'max_sponsored_products' => null,
                'features' => json_encode($base + ['analytics' => false, 'ai_tools' => false, 'black_hub' => false]),
                'is_active' => true, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'slug' => 'red', 'name' => 'Red Pepper', 'badge_color' => '#db142e', 'display_order' => 2, 'tier' => 1,
                'description' => 'Advanced analytics, AI tools and lower commission.',
                'price_monthly' => 49, 'price_yearly' => null, 'trial_days' => 0,
                'commission_rate' => null, 'commission_reduction' => 3,
                'max_products' => 150, 'max_images_per_product' => null, 'max_sponsored_products' => null,
                'features' => json_encode($base + ['analytics' => true, 'ai_tools' => true, 'black_hub' => false]),
                'is_active' => true, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'slug' => 'black', 'name' => 'Black Pepper', 'badge_color' => '#f59e0b', 'display_order' => 3, 'tier' => 2,
                'description' => 'Everything in Red + AI hub, sponsored products, VIP lounge.',
                'price_monthly' => 129, 'price_yearly' => null, 'trial_days' => 0,
                'commission_rate' => null, 'commission_reduction' => 6,
                'max_products' => null, 'max_images_per_product' => null, 'max_sponsored_products' => null,
                'features' => json_encode($base + ['analytics' => true, 'ai_tools' => true, 'black_hub' => true]),
                'is_active' => true, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Platform default commission (was CommissionService::TIERS / MIN_COMMISSION)
        DB::table('platform_settings')->insert([
            'key'   => 'commission.default',
            'value' => json_encode([
                'tiers' => [
                    ['min' => 0,       'max' => 100,  'rate' => 15],
                    ['min' => 100.01,  'max' => 200,  'rate' => 12],
                    ['min' => 200.01,  'max' => 300,  'rate' => 10],
                    ['min' => 300.01,  'max' => 500,  'rate' => 8],
                    ['min' => 500.01,  'max' => 1000, 'rate' => 5],
                    ['min' => 1000.01, 'max' => null, 'rate' => 3],
                ],
                'floor' => 3,
            ]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('subscription_plans');
    }
};
