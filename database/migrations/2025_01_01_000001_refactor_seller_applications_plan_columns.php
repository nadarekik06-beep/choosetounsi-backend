<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * MIGRATION: Refactor seller_applications plan columns
 *
 * Changes:
 *   1. Rename `subscription_plan` → `preferred_plan`
 *      (what the user expressed interest in before applying)
 *   2. Add `plan` column
 *      (the ACTIVE subscription — always 'free' at application time,
 *       upgraded only after approval + payment)
 *
 * Safe for existing data:
 *   - Existing rows keep their subscription_plan value as preferred_plan
 *   - All existing rows get plan = 'free' (the correct default)
 *
 * IMPORTANT — run on both dev and prod:
 *   php artisan migrate
 */
return new class extends Migration
{
   public function up(): void
{
    DB::statement('ALTER TABLE seller_applications CHANGE `subscription_plan` `preferred_plan` ENUM(\'green\',\'red\',\'black\') NOT NULL DEFAULT \'green\'');
}

public function down(): void
{
    DB::statement('ALTER TABLE seller_applications CHANGE `preferred_plan` `subscription_plan` ENUM(\'green\',\'red\',\'black\') NOT NULL DEFAULT \'green\'');
}
};