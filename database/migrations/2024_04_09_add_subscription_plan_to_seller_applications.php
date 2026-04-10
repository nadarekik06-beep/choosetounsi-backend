<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add subscription_plan to seller_applications.
     * Safe additive migration — existing rows get 'green' (free plan) by default.
     */
    public function up(): void
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->enum('subscription_plan', ['green', 'red', 'black'])
                  ->default('green')
                  ->after('status')
                  ->comment('green=Free, red=49DT/mo, black=129DT/mo');
        });
    }

    public function down(): void
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->dropColumn('subscription_plan');
        });
    }
};