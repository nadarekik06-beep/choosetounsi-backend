<?php
// database/migrations/2026_06_03_000001_create_seller_subscriptions_table.php
//
// PURPOSE
// ───────
// Adds a proper subscription lifecycle to ChooseTounsi without touching
// any existing column.
//
// NEW TABLE:  seller_subscriptions  — one active row per approved seller
// NEW TABLE:  subscription_plan_changes  — immutable audit log
// NEW COLS on products:      hidden_reason (for over-limit soft-hide)
// NEW COLS on promotions:    paused_reason, paused_at
// NEW COLS on sponsorships:  paused_reason, paused_at
//
// HOW IT INTEGRATES
// ─────────────────
// seller_applications.plan  ← still the live "current plan" field read by
//                             SellerPlanMiddleware.  We write both columns
//                             on every plan change so existing code keeps working.
// seller_subscriptions       ← adds lifecycle: billing dates, pending downgrade,
//                             status, grace period.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // ──────────────────────────────────────────────────────────────────────────
    // UP
    // ──────────────────────────────────────────────────────────────────────────
    public function up(): void
    {
        // ── 1. seller_subscriptions ───────────────────────────────────────────
        Schema::create('seller_subscriptions', function (Blueprint $table) {
            $table->id();

            // One subscription per seller application
            $table->foreignId('seller_application_id')
                  ->unique()
                  ->constrained('seller_applications')
                  ->onDelete('cascade');

            // Denormalised for fast lookups — always mirrors seller_applications.user_id
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // ── Active plan (mirrors seller_applications.plan) ─────────────────
            // We keep both in sync on every write. Middleware still reads
            // seller_applications.plan — this column is for our lifecycle logic.
            $table->enum('current_plan', ['free', 'red', 'black'])->default('free');

            // ── Pending downgrade (set when seller requests a downgrade) ────────
            // NULL = no pending change.
            // When set: seller keeps current_plan features until billing_cycle_end,
            // then we apply the pending_plan via SubscriptionSchedulerCommand.
            $table->enum('pending_plan', ['free', 'red', 'black'])->nullable()
                  ->comment('Scheduled downgrade target; applied at billing_cycle_end');

            // ── Lifecycle status ───────────────────────────────────────────────
            // active      = paid and current
            // grace_period= payment failed or downgrade window; features still on
            // past_due    = payment overdue, features degraded
            // canceled    = seller canceled; runs to end of period
            // expired     = billing period ended, no renewal → reverted to free
            // suspended   = admin action; all premium features off immediately
            $table->enum('status', [
                'active',
                'grace_period',
                'past_due',
                'canceled',
                'expired',
                'suspended',
            ])->default('active')->index();

            // ── Billing cycle ──────────────────────────────────────────────────
            // NULL for free plan (no cycle).
            // Set when seller first pays or upgrades.
            $table->date('billing_cycle_start')->nullable();
            $table->date('billing_cycle_end')->nullable()->index()
                  ->comment('Scheduler checks this daily to process renewals/expirations');

            // ── Grace period ───────────────────────────────────────────────────
            $table->timestamp('grace_period_ends_at')->nullable()->index()
                  ->comment('Features stay on until this timestamp even after cycle end');

            // ── Lifecycle timestamps ───────────────────────────────────────────
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->unsignedBigInteger('suspended_by')->nullable()
                  ->comment('Admin user_id who triggered suspension');

            // ── Admin notes ────────────────────────────────────────────────────
            $table->text('admin_note')->nullable();

            $table->timestamps();

            // Indexes for the scheduler's daily queries
            $table->index(['status', 'billing_cycle_end']);
            $table->index(['status', 'grace_period_ends_at']);
            $table->index('user_id');
        });

        // ── 2. subscription_plan_changes — immutable audit log ────────────────
        Schema::create('subscription_plan_changes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('seller_subscription_id')
                  ->constrained('seller_subscriptions')
                  ->onDelete('cascade');

            // Who triggered the change: seller self-service, admin force, scheduler
            $table->unsignedBigInteger('changed_by_user_id')->nullable()
                  ->comment('NULL = system/scheduler');
            $table->foreign('changed_by_user_id')->references('id')->on('users')->onDelete('set null');

            $table->enum('from_plan', ['free', 'red', 'black']);
            $table->enum('to_plan',   ['free', 'red', 'black']);

            $table->enum('change_type', [
                'upgrade',        // seller paid to go up
                'downgrade',      // seller requested to go down
                'admin_force',    // admin overrode the plan
                'payment_failure',// automatic downgrade on failed renewal
                'trial_end',      // future use
                'reactivation',   // came back from expired
            ]);

            // When the change actually took effect (may differ from created_at
            // for deferred downgrades scheduled at billing_cycle_end)
            $table->timestamp('effective_at');

            // Human-readable reason stored for support/audit
            $table->text('reason')->nullable();

            // Financial snapshot at time of change (for disputes)
            $table->decimal('amount_charged', 10, 3)->default(0)
                  ->comment('0 for free changes / downgrades');

            // created_at = when the record was written (immutable)
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — this table is append-only

            $table->index(['seller_subscription_id', 'effective_at'], 'idx_plan_changes_sub_date');            $table->index('change_type');
        });

        // ── 3. Add hidden_reason to products ─────────────────────────────────
        // When a seller downgrades and has more products than their new plan
        // allows, excess products are soft-hidden (not deleted).
        if (! Schema::hasColumn('products', 'hidden_reason')) {
            Schema::table('products', function (Blueprint $table) {
                $table->enum('hidden_reason', [
                    'over_plan_limit',   // plan downgrade pushed this product over the limit
                    'admin_suspended',   // admin disabled the product
                ])->nullable()->after('is_active')
                  ->comment('NULL = not hidden; set when soft-hidden due to plan change');

                $table->index('hidden_reason');
            });
        }

        // ── 4. Add paused columns to promotions ───────────────────────────────
        if (! Schema::hasColumn('promotions', 'paused_reason')) {
            Schema::table('promotions', function (Blueprint $table) {
                $table->enum('paused_reason', [
                    'plan_downgrade',  // seller's plan no longer supports this promotion
                    'manual',          // seller paused it themselves
                ])->nullable()->after('status')
                  ->comment('Why this promotion was paused');

                $table->timestamp('paused_at')->nullable()->after('paused_reason');
            });
        }

        // ── 5. Add paused columns to sponsorships ─────────────────────────────
        if (! Schema::hasColumn('sponsorships', 'paused_reason')) {
            Schema::table('sponsorships', function (Blueprint $table) {
                $table->enum('paused_reason', [
                    'plan_downgrade',
                    'manual',
                ])->nullable()->after('status')
                  ->comment('Why this sponsorship was paused');

                $table->timestamp('paused_at')->nullable()->after('paused_reason');
            });
        }

        // ── 6. Backfill: create seller_subscriptions rows for all existing ────
        //       approved sellers so the system is consistent from day one.
        $this->backfillExistingSellerSubscriptions();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DOWN
    // ──────────────────────────────────────────────────────────────────────────
    public function down(): void
    {
        // Remove added columns first (FK constraint order)
        if (Schema::hasColumn('sponsorships', 'paused_reason')) {
            Schema::table('sponsorships', function (Blueprint $table) {
                $table->dropColumn(['paused_reason', 'paused_at']);
            });
        }
        if (Schema::hasColumn('promotions', 'paused_reason')) {
            Schema::table('promotions', function (Blueprint $table) {
                $table->dropColumn(['paused_reason', 'paused_at']);
            });
        }
        if (Schema::hasColumn('products', 'hidden_reason')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('hidden_reason');
            });
        }

        Schema::dropIfExists('subscription_plan_changes');
        Schema::dropIfExists('seller_subscriptions');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // BACKFILL
    // ──────────────────────────────────────────────────────────────────────────
    private function backfillExistingSellerSubscriptions(): void
    {
        // Get all approved seller applications that don't already have a
        // seller_subscription row (safe to run multiple times).
        $applications = DB::table('seller_applications')
            ->where('status', 'approved')
            ->get(['id', 'user_id', 'plan']);

        foreach ($applications as $app) {
            // Already migrated? Skip.
            $exists = DB::table('seller_subscriptions')
                ->where('seller_application_id', $app->id)
                ->exists();

            if ($exists) continue;

            $plan = $app->plan ?? 'free';

            // For paid plans: set a billing cycle that "started" today and
            // ends in 30 days (we have no historical payment dates to use).
            // The scheduler will handle renewal from this point forward.
            $billingStart = null;
            $billingEnd   = null;
            $lastPayment  = null;

            if ($plan !== 'free') {
                // Look up the last successful payment for this seller
                $lastPaymentRow = DB::table('subscription_payments')
                    ->where('user_id', $app->user_id)
                    ->where('status', 'succeeded')
                    ->orderByDesc('created_at')
                    ->first();

                if ($lastPaymentRow) {
                    $billingStart = date('Y-m-d', strtotime($lastPaymentRow->created_at));
                    $billingEnd   = date('Y-m-d', strtotime('+30 days', strtotime($lastPaymentRow->created_at)));
                    $lastPayment  = $lastPaymentRow->created_at;
                } else {
                    // No payment record found (data was created before this system)
                    // Give them a clean 30-day cycle from today
                    $billingStart = date('Y-m-d');
                    $billingEnd   = date('Y-m-d', strtotime('+30 days'));
                }
            }

            DB::table('seller_subscriptions')->insert([
                'seller_application_id' => $app->id,
                'user_id'               => $app->user_id,
                'current_plan'          => $plan,
                'pending_plan'          => null,
                'status'                => 'active',
                'billing_cycle_start'   => $billingStart,
                'billing_cycle_end'     => $billingEnd,
                'grace_period_ends_at'  => null,
                'last_payment_at'       => $lastPayment,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }
    }
};