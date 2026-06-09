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
//   POST /api/seller/subscription/upgrade         — unchanged params, extended logic
//
// ZERO REGRESSIONS: the original upgrade flow still works identically.
// SellerPlanMiddleware still reads seller_applications.plan (unchanged).

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Notifications\SellerUpgradedNotification;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

        if (! $application) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'has_application' => false,
                    'status'          => null,
                    'plan'            => null,
                    'preferred_plan'  => null,
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
            'has_application' => true,
            'status'          => $application->status,
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
            ];
        }

        return response()->json(['success' => true, 'data' => $responseData]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/seller/subscription/upgrade
    //
    // Processes a subscription upgrade payment (mock for PFE).
    // Logic is identical to the original — extended to also update the
    // seller_subscriptions lifecycle row.
    // ─────────────────────────────────────────────────────────────────────────

    public function upgrade(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // ── 1. Validate request payload ───────────────────────────────────────
        $validated = $request->validate([
            'plan'            => ['required', Rule::in(['red', 'black'])],
            'card_number'     => ['required', 'string', 'regex:/^\d{13,19}$/'],
            'expiry_date'     => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'cvv'             => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'cardholder_name' => ['required', 'string', 'min:2', 'max:100'],
        ], [
            'card_number.regex' => 'Please enter a valid card number.',
            'expiry_date.regex' => 'Expiry must be in MM/YY format.',
            'cvv.regex'         => 'CVV must be 3 or 4 digits.',
        ]);

        // ── 2. Find approved seller application ───────────────────────────────
        $application = SellerApplication::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'You must have an approved seller account to upgrade.',
            ], 403);
        }

        // ── 3. Validate upgrade direction ─────────────────────────────────────
        $planHierarchy = ['free' => 0, 'red' => 1, 'black' => 2];
        $currentLevel  = $planHierarchy[$application->plan] ?? 0;
        $requestedLevel= $planHierarchy[$validated['plan']] ?? 0;

        if ($requestedLevel <= $currentLevel) {
            return response()->json([
                'success' => false,
                'message' => 'You are already on this plan or a higher plan.',
            ], 422);
        }

        // ── 4. Determine amount ───────────────────────────────────────────────
        $amounts = ['red' => 49.00, 'black' => 129.00];
        $amount  = $amounts[$validated['plan']];

        // ── 5. Mock payment + DB updates in a transaction ─────────────────────
        DB::beginTransaction();
        try {
            // Create payment record
            $payment = SubscriptionPayment::create([
                'user_id'         => $user->id,
                'plan'            => $validated['plan'],
                'amount'          => $amount,
                'currency'        => 'TND',
                'status'          => 'succeeded',
                'card_last4'      => substr(preg_replace('/\D/', '', $validated['card_number']), -4),
                'cardholder_name' => $validated['cardholder_name'],
            ]);

            // Update both seller_applications.plan AND the subscription lifecycle
            // SubscriptionService handles: application.plan + subscription row + audit log
            $this->subscriptionService->upgrade($application, $validated['plan'], $payment, $user);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('[SellerSubscriptionController::upgrade] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed. Please try again.',
            ], 500);
        }

        // ── 6. Notify all admins ──────────────────────────────────────────────
        try {
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                $admin->notify(new SellerUpgradedNotification($user, $payment));
            }
        } catch (\Throwable $e) {
            \Log::warning('SellerUpgradedNotification failed: ' . $e->getMessage());
        }

        // ── 7. Return success response ────────────────────────────────────────
        $planLabel = match($validated['plan']) {
            'red'   => 'Red Pepper',
            'black' => 'Black Pepper',
        };

        return response()->json([
            'success' => true,
            'message' => "Successfully upgraded to {$planLabel}!",
            'data'    => [
                'plan'       => $validated['plan'],
                'amount'     => $amount,
                'payment_id' => $payment->id,
            ],
        ]);
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
            'plan' => ['required', Rule::in(['free', 'red'])],
        ]);

        $application = SellerApplication::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'No approved seller account found.',
            ], 403);
        }

        $planHierarchy  = ['free' => 0, 'red' => 1, 'black' => 2];
        $currentLevel   = $planHierarchy[$application->plan] ?? 0;
        $requestedLevel = $planHierarchy[$validated['plan']] ?? 0;

        if ($requestedLevel >= $currentLevel) {
            return response()->json([
                'success' => false,
                'message' => 'The requested plan is not a downgrade from your current plan.',
            ], 422);
        }

        // Check for existing pending downgrade
        $sub = $this->subscriptionService->getOrCreateSubscription($application);
        if ($sub->hasPendingDowngrade()) {
            return response()->json([
                'success' => false,
                'message' => "A downgrade to {$sub->pending_plan} is already scheduled for {$sub->billing_cycle_end?->format('Y-m-d')}.",
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

        $planLabel = match($validated['plan']) {
            'free' => 'Green Pepper (Free)',
            'red'  => 'Red Pepper',
        };

        $effectiveDate = $sub->billing_cycle_end?->format('Y-m-d') ?? 'end of billing period';

        return response()->json([
            'success' => true,
            'message' => "Downgrade to {$planLabel} scheduled. You keep your current plan until {$effectiveDate}.",
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
            return response()->json(['success' => false, 'message' => 'No approved seller account found.'], 403);
        }

        try {
            $sub = $this->subscriptionService->cancelPendingDowngrade($application, $user);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to cancel downgrade.'], 500);
        }

        $planLabel = match($sub->current_plan) {
            'red'   => 'Red Pepper',
            'black' => 'Black Pepper',
            default => 'Green Pepper',
        };

        return response()->json([
            'success' => true,
            'message' => "Downgrade cancelled. You will remain on {$planLabel}.",
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