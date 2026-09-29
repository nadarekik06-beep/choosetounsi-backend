<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sponsorships become CPC campaigns (the table is kept to avoid churn).
 *
 * - status:        draft → active ⇄ paused → completed | cancelled | rejected
 *                  (expired kept for legacy rows)
 * - paused_reason: why an active campaign stopped serving
 * - budget / bid / spend / attribution columns
 * - open_product_id: generated column + UNIQUE → at most one open
 *   (draft|active|paused) campaign per product, even under races
 *
 * Legacy rows become pricing_model=legacy_daily (prepaid per day, served at the
 * floor CPC without click charges until their end date).
 */
return new class extends Migration
{
    private const STATUSES_NEW   = "'draft','active','paused','completed','cancelled','rejected','expired'";
    private const STATUSES_OLD   = "'active','expired','cancelled'";
    private const REASONS_NEW    = "'manual','budget_exhausted_today','wallet_empty','out_of_stock','plan_downgrade','product_inactive','admin'";
    private const REASONS_OLD    = "'plan_downgrade','manual'";

    public function up(): void
    {
        DB::statement("ALTER TABLE sponsorships MODIFY status ENUM(" . self::STATUSES_NEW . ") NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE sponsorships MODIFY paused_reason ENUM(" . self::REASONS_NEW . ") NULL DEFAULT NULL");

        Schema::table('sponsorships', function (Blueprint $table) {
            $table->enum('pricing_model', ['legacy_daily', 'cpc'])->default('cpc')->after('plan_type');
            $table->enum('goal', ['sales', 'visibility'])->default('sales')->after('pricing_model');
            $table->decimal('daily_budget', 12, 3)->nullable()->after('goal');
            $table->decimal('total_budget', 12, 3)->nullable()->after('daily_budget');
            $table->decimal('max_cpc', 12, 3)->nullable()->after('total_budget');
            $table->decimal('spent_total', 12, 3)->default(0)->after('max_cpc');
            $table->decimal('spent_today', 12, 3)->default(0)->after('spent_total');
            $table->date('spent_today_date')->nullable()->after('spent_today')->comment('Africa/Tunis date spent_today belongs to');
            $table->json('placements')->nullable()->after('spent_today_date')->comment('null = all placements');
            $table->unsignedTinyInteger('readiness_score')->nullable()->after('placements');
            $table->string('rejection_reason')->nullable()->after('paused_at');
            $table->timestamp('ended_at')->nullable()->after('end_at');
            $table->unsignedInteger('attributed_orders')->default(0)->after('conversions');
            $table->decimal('attributed_revenue', 12, 3)->default(0)->after('attributed_orders');

            $table->index(['status', 'end_at'], 'idx_sponsorships_status_end');
        });

        $this->migrateLegacyRows();

        Schema::table('sponsorships', function (Blueprint $table) {
            $table->unsignedBigInteger('open_product_id')
                ->storedAs("IF(status IN ('draft','active','paused'), product_id, NULL)")
                ->after('product_id');
            $table->unique('open_product_id', 'uq_sponsorships_open_product');
        });
    }

    private function migrateLegacyRows(): void
    {
        $minCpc = app(\App\Services\Ads\AdSettings::class)->float('min_cpc');

        // Every existing row predates CPC.
        DB::table('sponsorships')->update(['pricing_model' => 'legacy_daily', 'max_cpc' => $minCpc]);

        // "Paused" used to be stored as expired + paused_reason.
        $paused = DB::table('sponsorships')
            ->where('status', 'expired')->where('paused_reason', 'plan_downgrade')
            ->update(['status' => 'paused']);

        // Finished rows get an end timestamp for reports.
        DB::table('sponsorships')->where('status', 'expired')->whereNull('ended_at')
            ->update(['ended_at' => DB::raw('COALESCE(end_at, updated_at)')]);
        DB::table('sponsorships')->where('status', 'cancelled')->whereNull('ended_at')
            ->update(['ended_at' => DB::raw('updated_at')]);

        // Before the unique guard: keep only the newest open row per product.
        $dupes = 0;
        $products = DB::table('sponsorships')
            ->whereIn('status', ['draft', 'active', 'paused'])
            ->groupBy('product_id')->havingRaw('COUNT(*) > 1')
            ->pluck('product_id');
        foreach ($products as $productId) {
            $keep = DB::table('sponsorships')->where('product_id', $productId)
                ->whereIn('status', ['draft', 'active', 'paused'])
                ->orderByDesc('created_at')->orderByDesc('id')->value('id');
            $dupes += DB::table('sponsorships')->where('product_id', $productId)
                ->whereIn('status', ['draft', 'active', 'paused'])->where('id', '!=', $keep)
                ->update(['status' => 'cancelled', 'ended_at' => now()]);
        }

        // Products flagged sponsored without an open campaign (old Black Pepper toggle).
        $orphans = DB::table('products')->where('is_sponsored', true)
            ->whereNotExists(fn ($q) => $q->from('sponsorships')
                ->whereColumn('sponsorships.product_id', 'products.id')
                ->whereIn('sponsorships.status', ['active']))
            ->pluck('id');
        if ($orphans->isNotEmpty()) {
            DB::table('products')->whereIn('id', $orphans)->update(['is_sponsored' => false, 'sponsored_priority' => 0]);
        }

        Log::info('[migration] sponsorships → campaigns', [
            'paused_restored' => $paused, 'duplicate_open_cancelled' => $dupes,
            'orphan_flags_reset' => $orphans->all(),
        ]);
    }

    public function down(): void
    {
        Schema::table('sponsorships', function (Blueprint $table) {
            $table->dropUnique('uq_sponsorships_open_product');
            $table->dropColumn('open_product_id');
        });

        // Map the new states back onto the old enum.
        DB::table('sponsorships')->where('status', 'paused')->update(['status' => 'expired', 'paused_reason' => 'plan_downgrade']);
        DB::table('sponsorships')->whereIn('status', ['draft', 'rejected'])->update(['status' => 'cancelled']);
        DB::table('sponsorships')->where('status', 'completed')->update(['status' => 'expired']);
        DB::table('sponsorships')->whereNotIn('paused_reason', ['plan_downgrade', 'manual'])->update(['paused_reason' => null]);

        Schema::table('sponsorships', function (Blueprint $table) {
            $table->dropIndex('idx_sponsorships_status_end');
            $table->dropColumn([
                'pricing_model', 'goal', 'daily_budget', 'total_budget', 'max_cpc', 'spent_total', 'spent_today',
                'spent_today_date', 'placements', 'readiness_score', 'rejection_reason', 'ended_at',
                'attributed_orders', 'attributed_revenue',
            ]);
        });

        DB::statement("ALTER TABLE sponsorships MODIFY paused_reason ENUM(" . self::REASONS_OLD . ") NULL DEFAULT NULL");
        DB::statement("ALTER TABLE sponsorships MODIFY status ENUM(" . self::STATUSES_OLD . ") NOT NULL DEFAULT 'active'");
    }
};
