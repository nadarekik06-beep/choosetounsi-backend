<?php
// app/Http/Controllers/Api/Seller/SellerSubscriptionController.php
//
// FULL REPLACEMENT of the original file.
//
// NEW ENDPOINTS vs original:
//   POST /api/seller/subscription/downgrade       — schedule a deferred downgrade
//   DELETE /api/seller/subscription/downgrade     — cancel a pending downgrade
//
// PRESERVED endpoints (same URL, extended response):
//   GET  /api/seller/subscription                 — now returns full lifecycle data
//   POST /api/seller/subscription/upgrade         — retired (410): see SellerPaymentRequestController
//
// ZERO REGRESSIONS: the original upgrade flow still works identically.
// SellerPlanMiddleware still reads seller_applications.plan (unchanged).

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class SellerSubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptionService) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/seller/subscription
    //
    // Returns full subscription state including lifecycle data.
    // The frontend reads this to decide which UI to render.
    // ─────────────────────────────────────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $application = SellerApplication::where('user_id', $user->id)->first();

        // Role-level truth, independent of the application row: some approved
        // sellers have none, and they must never be offered the application form.
        $isApprovedSeller = $user->role === 'seller' && (bool) $user->is_approved;

        if (! $application) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'has_application'    => false,
                    'is_approved_seller' => $isApprovedSeller,
                    'status'             => null,
                    'plan'               => null,
                    'preferred_plan'     => null,
                ],
            ]);
        }

        // Get or create subscription lifecycle row
        $sub = null;
        if ($application->status === 'approved') {
            $sub = $this->subscriptionService->getOrCreateSubscription($application);
        }

        // Fetch last successful payment for billing history
        $lastPayment = SubscriptionPayment::where('user_id', $user->id)
            ->where('status', 'succeeded')
            ->latest()
            ->first();

        $responseData = [
            'has_application'    => true,
            'is_approved_seller' => $isApprovedSeller,
            'status'             => $application->status,
            'plan'            => $application->plan,
            'preferred_plan'  => $application->preferred_plan,
            'last_payment'    => $lastPayment ? [
                'plan'       => $lastPayment->plan,
                'amount'     => $lastPayment->amount,
                'created_at' => $lastPayment->created_at->format('Y-m-d\TH:i:s\Z'),
            ] : null,
        ];

        // Add lifecycle data if subscription row exists
        if ($sub) {
            $responseData['subscription'] = [
                'id'                    => $sub->id,
                'current_plan'          => $sub->current_plan,
                'pending_plan'          => $sub->pending_plan,
                'status'                => $sub->status,
                'status_label'          => $sub->status_label,
                'billing_cycle_start'   => $sub->billing_cycle_start?->format('Y-m-d'),
                'billing_cycle_end'     => $sub->billing_cycle_end?->format('Y-m-d'),
                'days_remaining'        => $sub->daysRemainingInCycle(),
                'grace_period_ends_at'  => $sub->grace_period_ends_at?->format('Y-m-d\TH:i:s\Z'),
                'has_pending_downgrade' => $sub->hasPendingDowngrade(),
                'max_products'          => $sub->maxProducts(),
                'billing_period'        => $sub->billing_period,
                'trial_ends_at'         => $sub->trial_ends_at?->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        // Plan definition (admin-managed) — the dashboard gates UI on these
        $plan = \App\Models\SubscriptionPlan::forSlug($sub?->current_plan ?? $application->plan);
        $responseData['plan_details'] = [
            'slug'                   => $plan->slug,
            'name'                   => $plan->name,
            'badge_color'            => $plan->badge_color,
            'tier'                   => $plan->tier,
            'tier_key'               => $plan->tierKey(),
            'price_monthly'          => (float) $plan->price_monthly,
            'price_yearly'           => $plan->price_yearly,
            'max_products'           => $plan->max_products,
            'max_images_per_product' => $plan->max_images_per_product,
            'max_sponsored_products' => $plan->max_sponsored_products,
            'features'               => $plan->features,
        ];
        if ($sub) {
            $responseData['commission'] = app(\App\Services\CommissionService::class)->effectiveSummary($sub);
        }

        return response()->json(['success' => true, 'data' => $responseData]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/seller/subscription/upgrade
    //
    // Retired: it accepted any card number and switched the plan without any
    // money moving. Paid plans are now requested through
    // POST /api/seller/payment-requests/plan-upgrade (paid via WhatsApp, activated
    // by an admin); Konnect / Flouci will plug into that flow later.
    // ─────────────────────────────────────────────────────────────────────────

    public function upgrade(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('payments.errors.use_payment_request'),
            'code'    => 'USE_PAYMENT_REQUEST',
        ], 410);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/seller/subscription/downgrade
    //
    // Schedules a deferred downgrade to take effect at end of billing cycle.
    // The seller keeps their current plan features until billing_cycle_end.
    //
    // Body: { "plan": "free"|"red" }
    // ─────────────────────────────────────────────────────────────────────────

    public function downgrade(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::exists('subscription_plans', 'slug')->whereNull('archived_at')],
        ]);

        $application = SellerApplication::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => __('seller.common.no_seller_account'),
            ], 403);
        }

        $current = \App\Models\SubscriptionPlan::forSlug($application->plan);
        $target  = \App\Models\SubscriptionPlan::forSlug($validated['plan']);

        if (!$current->isHigherThan($target)) {
            return response()->json([
                'success' => false,
                'message' => __('seller.subscription.not_downgrade'),
            ], 422);
        }

        // Check for existing pending downgrade
        $sub = $this->subscriptionService->getOrCreateSubscription($application);
        if ($sub->hasPendingDowngrade()) {
            return response()->json([
                'success' => false,
                'message' => __('seller.subscription.downgrade_exists', ['plan' => \App\Models\SubscriptionPlan::forSlug($sub->pending_plan)?->name ?? $sub->pending_plan, 'date' => $sub->billing_cycle_end?->translatedFormat('j F Y')]),
                'code'    => 'DOWNGRADE_ALREADY_SCHEDULED',
            ], 422);
        }

        try {
            $sub = $this->subscriptionService->scheduleDowngrade($application, $validated['plan'], $user);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $planLabel = $target->name;

        $effectiveDate = $sub->billing_cycle_end?->translatedFormat('j F Y') ?? __('seller.subscription.period_end');

        return response()->json([
            'success' => true,
            'message' => __('seller.subscription.downgrade_scheduled', ['plan' => $planLabel, 'date' => $effectiveDate]),
            'data'    => [
                'pending_plan'    => $sub->pending_plan,
                'effective_date'  => $effectiveDate,
                'days_remaining'  => $sub->daysRemainingInCycle(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/seller/subscription/downgrade
    //
    // Cancels a previously scheduled downgrade. Seller stays on current plan.
    // ─────────────────────────────────────────────────────────────────────────

    public function cancelDowngrade(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $application = SellerApplication::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json(['success' => false, 'message' => __('seller.common.no_seller_account')], 403);
        }

        try {
            $sub = $this->subscriptionService->cancelPendingDowngrade($application, $user);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => __('seller.subscription.cancel_downgrade_failed')], 500);
        }

        $planLabel = \App\Models\SubscriptionPlan::forSlug($sub->current_plan)->name;

        return response()->json([
            'success' => true,
            'message' => __('seller.subscription.downgrade_cancelled', ['plan' => $planLabel]),
            'data'    => [
                'current_plan'          => $sub->current_plan,
                'has_pending_downgrade' => false,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/seller/subscription/history
    //
    // Returns plan change audit log for the seller.
    // ─────────────────────────────────────────────────────────────────────────

    public function history(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $application = SellerApplication::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $sub = SellerSubscription::where('seller_application_id', $application->id)->first();

        if (! $sub) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $changes = $sub->planChanges()
            ->limit(20)
            ->get()
            ->map(fn($c) => [
                'from_plan'        => $c->from_plan,
                'to_plan'          => $c->to_plan,
                'change_type'      => $c->change_type,
                'change_type_label'=> $c->change_type_label,
                'effective_at'     => $c->effective_at->format('Y-m-d\TH:i:s\Z'),
                'reason'           => $c->reason,
                'amount_charged'   => (float) $c->amount_charged,
            ]);

        return response()->json(['success' => true, 'data' => $changes]);
    }
}