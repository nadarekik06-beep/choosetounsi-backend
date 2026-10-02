<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales forecast rebuild (see docs/FORECAST.md).
 *
 * Additive only: the old forecast_cache / product_event_signals /
 * regional_demand_cache tables are left in place (no code reads them any more);
 * product_event_signals rows are copied into calendar_events without their
 * guessed boost scores.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Real sales per product (variant_id = 0) and per variant, per day — rebuilt
        // from seller_orders by App\Services\Forecast\SalesSeries. Only days with
        // activity are stored; missing days mean zero.
        Schema::create('forecast_daily_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->default(0);
            $table->date('day');
            $table->unsignedInteger('units')->default(0);        // net of cancellations and returns
            $table->decimal('net_revenue', 12, 3)->default(0);   // after discounts and returns
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('promo_units')->default(0);  // units sold with a discount / flash sale applied
            $table->boolean('promo_day')->default(false);        // a promotion or sponsored boost was running
            // Leading signals — product rows (variant_id = 0) only
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('cart_adds')->default(0);
            $table->unsignedInteger('favorites')->default(0);

            $table->unique(['product_id', 'variant_id', 'day']);
            $table->index(['seller_id', 'day']);
            $table->index('day');
        });

        // One forecast per product / variant / whole shop per day; kept forever to
        // measure accuracy. product_id = 0 → whole shop, variant_id = 0 → whole product.
        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('product_id')->default(0);
            $table->unsignedBigInteger('variant_id')->default(0);
            $table->date('snapshot_date');
            $table->string('tier', 16);           // insufficient | category | blend | own
            $table->string('model', 24)->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);
            // Next 28 days, used for "Précision passée"
            $table->decimal('h28_point', 10, 2)->nullable();
            $table->unsignedInteger('h28_low')->nullable();
            $table->unsignedInteger('h28_high')->nullable();
            $table->unsignedInteger('actual_28')->nullable();      // filled once the 28 days have passed
            $table->timestamp('matured_at')->nullable();
            $table->longText('payload');                           // full result served to the dashboard
            $table->timestamps();

            $table->unique(['seller_id', 'product_id', 'variant_id', 'snapshot_date'], 'forecast_snapshots_unique');
            $table->index(['snapshot_date', 'matured_at']);
        });

        // Seller defaults (product_id NULL) and per-product overrides.
        Schema::create('forecast_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->unsignedSmallInteger('safety_days')->nullable();
            // Seller-level only
            $table->boolean('alerts_enabled')->default(true);
            $table->boolean('alerts_email')->default(true);
            $table->boolean('weekly_digest')->default(false);
            $table->unsignedSmallInteger('stockout_alert_days')->default(14);
            $table->timestamps();

            $table->unique(['seller_id', 'product_id']);
        });

        // Dedupes forecast alerts (stock-out, event, sales drop).
        Schema::create('forecast_alert_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('type', 20);
            $table->string('ref', 80);        // product:variant, event id…
            $table->date('sent_on');
            $table->timestamp('created_at')->nullable();

            $table->index(['seller_id', 'type', 'ref', 'sent_on']);
        });

        // Tunisian calendar — data only, never an uplift guess.
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);                 // same key every year: ramadan, aid_fitr, rentree…
            $table->string('name_fr', 120);
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('category_ids')->nullable();  // null = every category
            $table->boolean('is_active')->default(true);
            $table->string('source', 16)->default('admin');  // admin | hijri (computed, to verify)
            $table->timestamps();

            $table->unique(['key', 'starts_on']);
            $table->index(['starts_on', 'ends_on']);
        });

        // Sales change measured after an event ended, per category.
        Schema::create('calendar_event_effects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('calendar_event_id');
            $table->unsignedBigInteger('category_id');
            $table->decimal('event_daily_units', 10, 3);
            $table->decimal('baseline_daily_units', 10, 3);
            $table->decimal('change_pct', 7, 2)->nullable();   // null when the baseline had no sales
            $table->unsignedInteger('event_orders');
            $table->unsignedInteger('baseline_orders');
            $table->unsignedSmallInteger('event_days');
            $table->unsignedSmallInteger('baseline_days');
            $table->timestamps();

            $table->unique(['calendar_event_id', 'category_id']);
            $table->foreign('calendar_event_id')->references('id')->on('calendar_events')->onDelete('cascade');
        });

        $this->copyOldEvents();
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_effects');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('forecast_alert_log');
        Schema::dropIfExists('forecast_settings');
        Schema::dropIfExists('forecast_snapshots');
        Schema::dropIfExists('forecast_daily_sales');
    }

    /** Dates and names only — boost_score was a guess and is not carried over. */
    private function copyOldEvents(): void
    {
        if (!Schema::hasTable('product_event_signals')) return;

        $map = ['ramadan' => 'ramadan', 'eid' => 'aid', 'school' => 'rentree', 'summer' => 'summer',
                'economy' => 'soldes', 'tourism' => 'summer'];

        foreach (DB::table('product_event_signals')->get() as $ev) {
            $name = preg_replace('/\s+\d{4}$/', '', (string) $ev->event_name);
            DB::table('calendar_events')->insertOrIgnore([
                'key'          => $map[$ev->event_type] ?? substr((string) $ev->event_type, 0, 40),
                'name_fr'      => $name,
                'name_en'      => $name,
                'name_ar'      => $name,
                'starts_on'    => $ev->starts_at,
                'ends_on'      => $ev->ends_at,
                'category_ids' => null,
                'is_active'    => (bool) $ev->is_active,
                'source'       => 'admin',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }
};
