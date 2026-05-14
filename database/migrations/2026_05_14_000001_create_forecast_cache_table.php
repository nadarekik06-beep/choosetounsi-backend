<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * forecast_cache — stores pre-computed forecast results per product.
 *
 * FIXED for XAMPP MySQL (older versions reject `timestamp NOT NULL` without a default).
 * Solution: nullable() timestamps — Laravel sets them at the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_cache', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->onDelete('cascade');

            $table->string('cache_key', 100);
            $table->longText('payload');

            // FIX: nullable() avoids MySQL 5.x strict-mode timestamp error
            $table->timestamp('expires_at')->nullable();

            $table->string('computed_by', 50)->default('laravel');
            $table->unsignedInteger('data_points')->default(0);
            $table->decimal('confidence_score', 5, 2)->default(0);

            $table->timestamps();

            $table->unique(['product_id', 'cache_key']);
            $table->index('expires_at');
            $table->index(['product_id', 'cache_key', 'expires_at']);
        });

        /**
         * product_event_signals — Tunisia-specific demand events.
         *
         * Pre-populated with known Tunisian calendar events.
         * Used by the forecast engine to boost/dampen predictions
         * for specific months without calling an external API.
         *
         * The FastAPI / PyTrends layer can UPDATE boost_score with
         * real search trend data later.
         */
        Schema::create('product_event_signals', function (Blueprint $table) {
            $table->id();

            $table->string('event_slug', 60)->unique();   // e.g. 'ramadan_2025'
            $table->string('event_name', 120);             // e.g. 'Ramadan 2025'
            $table->string('event_type', 40);              // ramadan|eid|summer|school|tourism|economy

            // Approximate date range (Islamic events vary by year — stored per year)
            $table->date('starts_at');
            $table->date('ends_at');

            // Which category slugs are boosted by this event
            // null = affects all categories
            $table->json('affected_categories')->nullable();

            // The demand multiplier this event applies (1.0 = neutral)
            $table->decimal('boost_score', 5, 3)->default(1.000)
                  ->comment('Multiplier applied to base forecast during this event');

            // Which Tunisian governorates are most affected
            $table->json('top_regions')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
            $table->index('event_type');
        });

        /**
         * regional_demand_cache — per-product, per-wilaya demand data.
         *
         * Built from orders.wilaya (already in your DB).
         * Refreshed every 24 hours per product.
         */
        Schema::create('regional_demand_cache', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->onDelete('cascade');

            $table->string('wilaya', 100);

            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('total_units')->default(0);
            $table->decimal('total_revenue', 12, 3)->default(0);

            // Normalized 0-100 demand score for heatmap rendering
            $table->decimal('demand_index', 5, 2)->default(0);

            // FIX: nullable() for XAMPP MySQL compatibility
            $table->timestamp('computed_at')->nullable()->useCurrent();
            $table->timestamp('expires_at')->nullable();

            $table->unique(['product_id', 'wilaya']);
            $table->index(['product_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regional_demand_cache');
        Schema::dropIfExists('product_event_signals');
        Schema::dropIfExists('forecast_cache');
    }
};