<?php
// app/Http/Controllers/Admin/AdminSubscriptionController.php
//
// NEW controller — adds subscription management to the admin panel.
//
// Endpoints (all under /api/admin/subscriptions):
//   GET    /                         list all seller subscriptions with filters
//   GET    /{sellerId}               single seller subscription detail
//   POST   /{sellerId}/force-plan    admin force plan change (immediate)
//   POST   /{sellerId}/suspend       suspend a seller's subscription
//   POST   /{sellerId}/reinstate     reinstate a suspended subscription
//   GET    /{sellerId}/history       plan change audit log

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class AdminSubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptionService) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/subscriptions
    //
    // Paginated list of all seller subscriptions with filters.
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = SellerSubscription::with(['user', 'sellerApplication'])
            ->orderByDesc('updated_at');

        // Filter by plan
        if ($plan = $request->query('plan')) {
            $query->where('current_plan', $plan);
        }

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Filter by pending downgrade
        if ($request->boolean('pending_downgrade')) {
            $query->whereNotNull('pending_plan');
        }

        // Search by seller name or email
        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $paginated = $query->paginate($request->query('per_page', 20));

        $paginated->getCollection()->transform(fn($sub) => $this->formatSubscription($sub));

        return response()->json(['success' => true, 'data' => $paginated]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/subscriptions/{sellerId}
    // ─────────────────────────────────────────────────────────────────────────

    public function show(int $sellerId): JsonResponse
    {
        $application = SellerApplication::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json(['success' => false, 'message' => 'Seller not found.'], 404);
        }

        $sub = $this->subscriptionService->getOrCreateSubscription($application);

        $history = $sub->planChanges()
            ->with('changedBy')
            ->limit(30)
            ->get()
            ->map(fn($c) => [
                'from_plan'         => $c->from_plan,
                'to_plan'           => $c->to_plan,
                'change_type'       => $c->change_type,
                'change_type_label' => $c->change_type_label,
                'effective_at'      => $c->effective_at->format('Y-m-d\TH:i:s\Z'),
                'reason'            => $c->reason,
                'amount_charged'    => (float) $c->amount_charged,
                'changed_by'        => $c->changedBy ? [
                    'id'   => $c->changedBy->id,
                    'name' => $c->changedBy->name,
                    'role' => $c->changedBy->role,
                ] : null,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'subscription' => $this->formatSubscription($sub),
                'history'      => $history,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/subscriptions/{sellerId}/force-plan
    //
    // Body: { "plan": "free"|"red"|"black", "reason": "..." }
    // Admin forces an immediate plan change bypassing billing cycle.
    // ─────────────────────────────────────────────────────────────────────────

    public function forcePlan(Request $request, int $sellerId): JsonResponse
    {
        $validated = $request->validate([
            'plan'   => ['required', Rule::in(['free', 'red', 'black'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $application = SellerApplication::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json(['success' => false, 'message' => 'Seller not found.'], 404);
        }

        if ($application->plan === $validated['plan']) {
            return response()->json([
                'success' => false,
                'message' => "Seller is already on the {$validated['plan']} plan.",
            ], 422);
        }

        try {
            $sub = $this->subscriptionService->adminForce(
                $application,
                $validated['plan'],
                $request->user(),
                $validated['reason']
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Plan changed to {$validated['plan']} immediately.",
            'data'    => $this->formatSubscription($sub),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/subscriptions/{sellerId}/suspend
    // ─────────────────────────────────────────────────────────────────────────

    public function suspend(Request $request, int $sellerId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $application = SellerApplication::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json(['success' => false, 'message' => 'Seller not found.'], 404);
        }

        try {
            $sub = $this->subscriptionService->suspend($application, $request->user(), $validated['reason']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Subscription suspended.',
            'data'    => $this->formatSubscription($sub),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/subscriptions/{sellerId}/reinstate
    //
    // Reinstates a suspended subscription back to active.
    // ─────────────────────────────────────────────────────────────────────────

    public function reinstate(Request $request, int $sellerId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $application = SellerApplication::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json(['success' => false, 'message' => 'Seller not found.'], 404);
        }

        $sub = SellerSubscription::where('seller_application_id', $application->id)->first();

        if (! $sub || $sub->status !== 'suspended') {
            return response()->json(['success' => false, 'message' => 'Subscription is not suspended.'], 422);
        }

        $sub->update([
            'status'       => 'active',
            'suspended_at' => null,
            'suspended_by' => null,
            'admin_note'   => $validated['reason'] ?? 'Reinstated by admin',
        ]);

        // Also reinstate seller_applications.plan if it was cleared
        // (plan is still set — suspension doesn't change the plan, only access)

        return response()->json([
            'success' => true,
            'message' => 'Subscription reinstated.',
            'data'    => $this->formatSubscription($sub->fresh()),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: format subscription for API response
    // ─────────────────────────────────────────────────────────────────────────

    private function formatSubscription(SellerSubscription $sub): array
    {
        $sub->loadMissing(['user', 'sellerApplication']);

        return [
            'id'                    => $sub->id,
            'user_id'               => $sub->user_id,
            'seller_name'           => $sub->user?->name,
            'seller_email'          => $sub->user?->email,
            'business_name'         => $sub->sellerApplication?->business_name,
            'current_plan'          => $sub->current_plan,
            'pending_plan'          => $sub->pending_plan,
            'status'                => $sub->status,
            'status_label'          => $sub->status_label,
            'billing_cycle_start'   => $sub->billing_cycle_start?->format('Y-m-d'),
            'billing_cycle_end'     => $sub->billing_cycle_end?->format('Y-m-d'),
            'days_remaining'        => $sub->daysRemainingInCycle(),
            'grace_period_ends_at'  => $sub->grace_period_ends_at?->format('Y-m-d\TH:i:s\Z'),
            'has_pending_downgrade' => $sub->hasPendingDowngrade(),
            'last_payment_at'       => $sub->last_payment_at?->format('Y-m-d\TH:i:s\Z'),
            'suspended_at'          => $sub->suspended_at?->format('Y-m-d\TH:i:s\Z'),
            'admin_note'            => $sub->admin_note,
            'max_products'          => $sub->maxProducts(),
        ];
    }
}