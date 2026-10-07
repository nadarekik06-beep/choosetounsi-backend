<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centre de profit: richer revenue goals + per-seller goal alert settings.
 *
 * revenue_goals keeps its rows and its (seller_id, month) key; the new columns
 * are optional targets, the preset used, alert bookkeeping (milestones fire
 * once per goal) and a month-end snapshot of what was achieved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revenue_goals', function (Blueprint $table) {
            $table->unsignedInteger('orders_target')->nullable()->after('goal_amount');
            $table->decimal('net_target', 12, 3)->nullable()->after('orders_target');
            $table->string('preset', 12)->nullable()->after('net_target');      // prudent|realistic|ambitious|custom
            $table->json('milestones_sent')->nullable()->after('preset');       // [25, 50, …]
            $table->timestamp('last_pace_alert_at')->nullable()->after('milestones_sent');
            $table->decimal('achieved_revenue', 12, 3)->nullable()->after('last_pace_alert_at');
            $table->unsignedInteger('achieved_orders')->nullable()->after('achieved_revenue');
            $table->decimal('achieved_net', 12, 3)->nullable()->after('achieved_orders');
            $table->timestamp('closed_at')->nullable()->after('achieved_net');
        });

        Schema::create('profit_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->unique();
            $table->boolean('enabled')->default(true);
            $table->boolean('milestones')->default(true);
            $table->boolean('pace')->default(true);
            $table->boolean('weekly')->default(false);
            $table->boolean('monthly_recap')->default(true);
            $table->boolean('new_goal_reminder')->default(true);
            $table->boolean('channel_bell')->default(true);
            $table->boolean('channel_email')->default(false);
            $table->date('last_weekly_on')->nullable();
            $table->string('last_recap_month', 7)->nullable();
            $table->string('last_reminder_month', 7)->nullable();
            $table->timestamps();

            $table->foreign('seller_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_alert_settings');
        Schema::table('revenue_goals', function (Blueprint $table) {
            $table->dropColumn([
                'orders_target', 'net_target', 'preset', 'milestones_sent', 'last_pace_alert_at',
                'achieved_revenue', 'achieved_orders', 'achieved_net', 'closed_at',
            ]);
        });
    }
};
