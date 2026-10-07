<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor Insights (Black Pepper → Analyse des visiteurs): behavioral funnel per product.
 *
 *  - user_interactions gets the traffic source, the device and an exclusion flag
 *    (the seller's own visits, staff, bots) so the funnel only counts real buyers.
 *  - product_funnel_events: the two high-volume funnel steps that the personalization
 *    log doesn't need (impressions, checkout starts). Pruned by funnel:aggregate.
 *  - product_daily_stats / product_daily_traffic: nightly roll-ups the page reads
 *    (never raw events). Traffic = per source × device, so source conversion and the
 *    device split don't need the raw log either.
 *  - funnel_benchmarks: medians per subcategory / category / platform and window.
 *  - growth_actions: actions can now come from Visitor Insights (origin), with the
 *    problem they were meant to fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_interactions', function (Blueprint $table) {
            $table->string('traffic_source', 12)->nullable()->after('source_section');
            $table->enum('device', ['mobile', 'tablet', 'desktop'])->nullable()->after('traffic_source');
            $table->boolean('funnel_excluded')->default(false)->after('device')
                ->comment('Seller viewing own product, staff or bot-like session: kept for history, not counted in funnels');
        });

        Schema::create('product_funnel_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id');
            $table->enum('event', ['impression', 'checkout_start']);
            $table->string('traffic_source', 12);
            $table->enum('device', ['mobile', 'tablet', 'desktop'])->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('session_id', 36)->nullable();
            $table->char('ref', 36)->nullable()->comment('checkout_start: the checkout attempt id, one per session per order');
            $table->boolean('excluded')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'event'], 'idx_pfe_day');
            $table->index(['product_id', 'created_at'], 'idx_pfe_product');
            // One checkout start per session, product and checkout attempt (NULL refs = impressions, not constrained)
            $table->unique(['event', 'session_id', 'product_id', 'ref'], 'uq_pfe_checkout');
        });

        Schema::create('product_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('subcategory_id')->nullable();
            $table->date('date');                                   // Africa/Tunis day
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('views')->default(0);           // unique per session per day
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('add_to_cart')->default(0);
            $table->unsignedInteger('wishlist')->default(0);
            $table->unsignedInteger('checkout_started')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->decimal('revenue', 12, 3)->default(0);
            // Views per source (sum = views)
            $table->unsignedInteger('views_search')->default(0);
            $table->unsignedInteger('views_category')->default(0);
            $table->unsignedInteger('views_home')->default(0);
            $table->unsignedInteger('views_sponsored')->default(0);
            $table->unsignedInteger('views_storefront')->default(0);
            $table->unsignedInteger('views_external')->default(0);
            $table->unsignedInteger('views_direct')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'date'], 'uq_pds_product_day');
            $table->index(['seller_id', 'date'], 'idx_pds_seller_day');
            $table->index(['date', 'subcategory_id'], 'idx_pds_day_sub');
            $table->index(['date', 'category_id'], 'idx_pds_day_cat');
        });

        Schema::create('product_daily_traffic', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id');
            $table->date('date');
            $table->string('traffic_source', 12);
            $table->string('device', 8);                            // mobile | tablet | desktop | unknown
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('add_to_cart')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->decimal('revenue', 12, 3)->default(0);

            $table->unique(['product_id', 'date', 'traffic_source', 'device'], 'uq_pdt');
            $table->index(['seller_id', 'date'], 'idx_pdt_seller_day');
        });

        Schema::create('funnel_benchmarks', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['subcategory', 'category', 'platform']);
            $table->unsignedBigInteger('scope_id')->default(0);     // 0 = platform
            $table->unsignedSmallInteger('window_days');            // 7 | 30 | 90
            $table->date('end_date');                               // last day included
            $table->unsignedInteger('products')->default(0);
            $table->unsignedInteger('sellers')->default(0);
            $table->unsignedInteger('views')->default(0);
            // Medians across products with a large enough sample (null = not enough of them)
            $table->double('ctr')->nullable();
            $table->double('view_to_cart')->nullable();
            $table->double('cart_to_order')->nullable();
            $table->double('conversion')->nullable();
            $table->double('views_per_product')->nullable();
            $table->double('impressions_per_product')->nullable();
            $table->decimal('median_price', 12, 3)->nullable();
            $table->decimal('median_delivery_fee', 8, 3)->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_id', 'window_days'], 'uq_fb_scope');
        });

        Schema::table('growth_actions', function (Blueprint $table) {
            $table->string('origin', 20)->default('growth_radar')->after('seller_id');
            $table->string('stage', 16)->nullable()->after('card_type');
            $table->string('problem_code', 32)->nullable()->after('stage');
            $table->index(['seller_id', 'origin', 'starts_at'], 'idx_ga_origin');
        });
    }

    public function down(): void
    {
        Schema::table('growth_actions', function (Blueprint $table) {
            $table->dropIndex('idx_ga_origin');
            $table->dropColumn(['origin', 'stage', 'problem_code']);
        });
        Schema::dropIfExists('funnel_benchmarks');
        Schema::dropIfExists('product_daily_traffic');
        Schema::dropIfExists('product_daily_stats');
        Schema::dropIfExists('product_funnel_events');
        Schema::table('user_interactions', function (Blueprint $table) {
            $table->dropColumn(['traffic_source', 'device', 'funnel_excluded']);
        });
    }
};
