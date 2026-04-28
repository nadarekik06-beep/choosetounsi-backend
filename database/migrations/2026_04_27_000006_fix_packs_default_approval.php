<?php
// database/migrations/xxxx_fix_packs_default_approval.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Auto-approve all existing packs
        DB::table('packs')->update(['is_approved' => true]);

        // Change column default
        \Illuminate\Support\Facades\Schema::table('packs', function ($table) {
            $table->boolean('is_approved')->default(true)->change();
        });
    }

    public function down(): void {}
};