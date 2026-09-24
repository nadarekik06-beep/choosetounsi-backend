<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan slugs were ENUM('free','red','black') in several tables, which made
 * admin-created plans impossible. Widen them to VARCHAR(30) — existing values
 * are preserved as-is (no data is dropped or rewritten).
 *
 * Also adds the 'trial' subscription status and new plan-change types.
 */
return new class extends Migration
{
    private array $planColumns = [
        ['seller_applications',       'plan',         "NOT NULL DEFAULT 'free'"],
        ['seller_subscriptions',      'current_plan', "NOT NULL DEFAULT 'free'"],
        ['seller_subscriptions',      'pending_plan', 'NULL DEFAULT NULL'],
        ['order_items',               'plan_used',    "NOT NULL DEFAULT 'free'"],
        ['subscription_payments',     'plan',         'NOT NULL'],
        ['subscription_plan_changes', 'from_plan',    'NOT NULL'],
        ['subscription_plan_changes', 'to_plan',      'NOT NULL'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        foreach ($this->planColumns as [$table, $column, $nullability]) {
            if (!Schema::hasColumn($table, $column)) continue;
            // Keep NULL-ability consistent with the current definition
            $isNullable = DB::selectOne(
                'SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            )->n === 'YES';
            $def = $isNullable ? 'NULL DEFAULT NULL' : $nullability;
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(30) {$def}");
        }

        DB::statement("ALTER TABLE `seller_subscriptions` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE `subscription_plan_changes` MODIFY `change_type` VARCHAR(30) NOT NULL");
    }

    public function down(): void
    {
        // Intentionally a no-op: narrowing back to ENUM could reject custom plan slugs.
    }
};
