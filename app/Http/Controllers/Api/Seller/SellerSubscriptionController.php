<?php
// app/Http/Controllers/Api/Seller/SellerSubscriptionController.php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Notifications\SellerUpgradedNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SellerSubscriptionController extends Controller
{
    /**
     * GET /api/seller/subscription
     *
     * Returns the authenticated seller's current subscription state.
     * Used by the /become-a-vendor page to decide which UI to render.
     */
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

        // Fetch last successful payment for billing history
        $lastPayment = SubscriptionPayment::where('user_id', $user->id)
            ->where('status', 'succeeded')
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'has_application' => true,
                'status'          => $application->status,        // pending|approved|rejected
                'plan'            => $application->plan,           // free|red|black
                'preferred_plan'  => $application->preferred_plan, // green|red|black
                'last_payment'    => $lastPayment ? [
                    'plan'       => $lastPayment->plan,
                    'amount'     => $lastPayment->amount,
                    'created_at' => $lastPayment->created_at->format('Y-m-d\TH:i:s\Z'),
                ] : null,
            ],
        ]);
    }

    /**
     * POST /api/seller/subscription/upgrade
     *
     * Processes a subscription upgrade payment (mock for PFE).
     * - Validates card inputs (format only — no real charge)
     * - Validates plan is an actual upgrade (not downgrade)
     * - Creates SubscriptionPayment record
     * - Updates seller_applications.plan
     * - Notifies all admins
     */
    public function upgrade(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // ── 1. Validate request payload ────────────────────────────────────────
        $validated = $request->validate([
            'plan'             => ['required', Rule::in(['red', 'black'])],
            'card_number'      => ['required', 'string', 'regex:/^\d{13,19}$/'],
            'expiry_date'      => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'cvv'              => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'cardholder_name'  => ['required', 'string', 'min:2', 'max:100'],
        ], [
            'card_number.regex'    => 'Please enter a valid card number.',
            'expiry_date.regex'    => 'Expiry must be in MM/YY format.',
            'cvv.regex'            => 'CVV must be 3 or 4 digits.',
        ]);

        // ── 2. Find the seller's application ──────────────────────────────────
        $application = SellerApplication::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'You must have an approved seller account to upgrade.',
            ], 403);
        }

        // ── 3. Validate upgrade direction (no downgrade allowed) ──────────────
        $planHierarchy = ['free' => 0, 'red' => 1, 'black' => 2];
        $currentLevel  = $planHierarchy[$application->plan] ?? 0;
        $requestedLevel= $planHierarchy[$validated['plan']] ?? 0;

        if ($requestedLevel <= $currentLevel) {
            return response()->json([
                'success' => false,
                'message' => 'You are already on this plan or a higher plan.',
            ], 422);
        }

        // ── 4. Determine amount ────────────────────────────────────────────────
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

            // Update active plan on the application
            $application->update(['plan' => $validated['plan']]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed. Please try again.',
            ], 500);
        }

        // ── 6. Notify all admins (outside transaction — failure is non-fatal) ──
        try {
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                $admin->notify(new SellerUpgradedNotification($user, $payment));
            }
        } catch (\Throwable $e) {
            // Swallow — payment already succeeded, notification failure must not roll it back
            \Log::warning('SellerUpgradedNotification failed: ' . $e->getMessage());
        }

        // ── 7. Return success response ─────────────────────────────────────────
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
}