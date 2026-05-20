<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // Drop the old constraint (user_id + product_id only) — only if it still exists.
            // It may have already been dropped by the add_variant_id_to_cart migration.
            $existingIndexes = DB::select("SHOW INDEX FROM carts WHERE Key_name = 'carts_user_id_product_id_unique'");
            if (!empty($existingIndexes)) {
                $table->dropUnique(['user_id', 'product_id']);
            }

            // Add the correct constraint that includes variant_id — only if not already there.
            $variantUnique = DB::select("SHOW INDEX FROM carts WHERE Key_name = 'carts_user_product_variant_unique'");
            if (empty($variantUnique)) {
                $table->unique(
                    ['user_id', 'product_id', 'variant_id'],
                    'carts_user_product_variant_unique'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $variantUnique = DB::select("SHOW INDEX FROM carts WHERE Key_name = 'carts_user_product_variant_unique'");
            if (!empty($variantUnique)) {
                $table->dropUnique('carts_user_product_variant_unique');
            }
            $oldUnique = DB::select("SHOW INDEX FROM carts WHERE Key_name = 'carts_user_id_product_id_unique'");
            if (empty($oldUnique)) {
                $table->unique(['user_id', 'product_id']);
            }
        });
    }
};
