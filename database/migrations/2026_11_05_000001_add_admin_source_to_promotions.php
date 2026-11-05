<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin promotions reuse the seller promotion tables:
 *   created_by           seller | admin (who created it; existing rows: seller)
 *   created_by_admin_id  the admin user who created it (admin promotions only)
 *   funded_by            who pays the discount. Owner's decision (2026-11-05):
 *                        always 'seller' (seller paid on the discounted price,
 *                        exactly like a seller promotion). Kept as a column so
 *                        finance can tell later if this ever changes.
 * promotions.seller_id stays the product owner, so the seller dashboard,
 * settlements and earnings keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('promotions', 'created_by')) {
            return;
        }
        Schema::table('promotions', function (Blueprint $table) {
            $table->enum('created_by', ['seller', 'admin'])->default('seller')->after('seller_id');
            $table->foreignId('created_by_admin_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->string('funded_by', 10)->default('seller')->after('created_by_admin_id');
            $table->index(['created_by', 'status'], 'idx_promotions_source_status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('promotions', 'created_by')) {
            return;
        }
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndex('idx_promotions_source_status');
            $table->dropConstrainedForeignId('created_by_admin_id');
            $table->dropColumn(['created_by', 'funded_by']);
        });
    }
};
