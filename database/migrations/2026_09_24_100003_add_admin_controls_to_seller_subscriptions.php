<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_subscriptions', function (Blueprint $table) {
            $table->string('billing_period', 10)->default('monthly')->after('status');   // monthly | yearly
            $table->timestamp('trial_ends_at')->nullable()->after('billing_cycle_end');
            $table->text('cancel_reason')->nullable()->after('canceled_at');

            // Per-seller commission override — wins over the plan rate while active
            $table->decimal('commission_override', 5, 2)->nullable()->after('admin_note');
            $table->timestamp('commission_override_expires_at')->nullable()->after('commission_override');
            $table->text('commission_override_reason')->nullable()->after('commission_override_expires_at');
            $table->foreignId('commission_override_set_by')->nullable()->after('commission_override_reason')
                  ->constrained('users')->nullOnDelete();
        });

        // Every approved seller gets a subscription row, so the admin list is complete
        // (previously rows were only created lazily on first access).
        $missing = DB::table('seller_applications as a')
            ->leftJoin('seller_subscriptions as s', 's.seller_application_id', '=', 'a.id')
            ->where('a.status', 'approved')
            ->whereNull('s.id')
            ->select('a.id', 'a.user_id', 'a.plan')
            ->get();

        foreach ($missing as $app) {
            $paid = ($app->plan ?? 'free') !== 'free';
            DB::table('seller_subscriptions')->insert([
                'seller_application_id' => $app->id,
                'user_id'               => $app->user_id,
                'current_plan'          => $app->plan ?? 'free',
                'status'                => 'active',
                'billing_period'        => 'monthly',
                'billing_cycle_start'   => $paid ? now()->toDateString() : null,
                'billing_cycle_end'     => $paid ? now()->addDays(30)->toDateString() : null,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('seller_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_override_set_by');
            $table->dropColumn([
                'billing_period', 'trial_ends_at', 'cancel_reason',
                'commission_override', 'commission_override_expires_at', 'commission_override_reason',
            ]);
        });
    }
};
