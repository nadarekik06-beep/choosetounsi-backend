<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Request changes" moderation state.
 *
 * A product is in `changes_requested` when is_approved = false and
 * changes_requested_at IS NOT NULL. It returns to `pending` as soon as the
 * seller edits it (SellerProductController::update clears the column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'changes_requested_at')) {
                $table->timestamp('changes_requested_at')->nullable()->after('rejection_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'changes_requested_at')) {
                $table->dropColumn('changes_requested_at');
            }
        });
    }
};
