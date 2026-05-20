<?php
// database/migrations/xxxx_redesign_pack_items.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pack_items', function (Blueprint $table) {
            // Drop the old single variant FK
            if (Schema::hasColumn('pack_items', 'variant_id')) {
                $table->dropForeign(['variant_id']);
                $table->dropColumn('variant_id');
            }

            // JSON array of allowed variant IDs — null means "all active variants"
            $table->json('allowed_variant_ids')
                ->nullable()
                ->after('product_id')
                ->comment('null = all active variants; [1,2,3] = only these variants');
        });
    }

    public function down(): void
    {
        Schema::table('pack_items', function (Blueprint $table) {
            $table->dropColumn('allowed_variant_ids');
            $table->foreignId('variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();
        });
    }
};