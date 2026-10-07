<?php
// app/Http/Controllers/Admin/AdminPlanDisplayFeatureController.php
//
// Pricing-page features of one plan, one at a time (the plan editor also
// saves the whole list through PUT /subscription-plans/{id}).
//   GET    /api/admin/subscription-plans/{planId}/display-features
//   POST   /api/admin/subscription-plans/{planId}/display-features
//   PUT    /api/admin/subscription-plans/{planId}/display-features/reorder   { ids: [..] }
//   PUT    /api/admin/subscription-plans/{planId}/display-features/{id}
//   DELETE /api/admin/subscription-plans/{planId}/display-features/{id}
// Every change is written to subscription_audit_logs with the plan's before / after.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subscriptions\DisplayFeatureRequest;
use App\Models\PlanDisplayFeature;
use App\Models\SubscriptionAuditLog;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPlanDisplayFeatureController extends Controller
{
    public function index(int $planId): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($planId);
        return response()->json(['success' => true, 'data' => $this->list($plan)]);
    }

    public function store(DisplayFeatureRequest $request, int $planId): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($planId);
        if ($plan->displayFeatures()->count() >= PlanDisplayFeature::MAX_PER_PLAN) {
            return response()->json([
                'success' => false,
                'message' => 'A plan can show at most ' . PlanDisplayFeature::MAX_PER_PLAN . ' features on the pricing page.',
            ], 422);
        }

        $feature = $this->audited($plan, $request, 'display_feature_added', fn() => $plan->displayFeatures()->create(
            $this->attrs($request) + ['included' => true, 'highlight' => false, 'sort_order' => (int) $plan->displayFeatures()->max('sort_order') + 1]
        ));

        return response()->json(['success' => true, 'message' => 'Feature added.', 'data' => $feature->toSnapshot()], 201);
    }

    public function update(DisplayFeatureRequest $request, int $planId, int $id): JsonResponse
    {
        $plan    = SubscriptionPlan::findOrFail($planId);
        $feature = $plan->displayFeatures()->findOrFail($id);

        $this->audited($plan, $request, 'display_feature_updated', fn() => $feature->update($this->attrs($request)));

        return response()->json(['success' => true, 'message' => 'Feature updated.', 'data' => $feature->fresh()->toSnapshot()]);
    }

    public function destroy(Request $request, int $planId, int $id): JsonResponse
    {
        $plan    = SubscriptionPlan::findOrFail($planId);
        $feature = $plan->displayFeatures()->findOrFail($id);

        $this->audited($plan, $request, 'display_feature_removed', fn() => $feature->delete());

        return response()->json(['success' => true, 'message' => 'Feature removed.', 'data' => $this->list($plan)]);
    }

    public function reorder(Request $request, int $planId): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($planId);
        $current = $plan->displayFeatures()->pluck('id')->all();
        $data = $request->validate([
            'ids'    => ['required', 'array', 'size:' . count($current)],
            'ids.*'  => ['integer', 'distinct', 'in:' . implode(',', $current ?: [0])],
            'reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ], ['ids.size' => 'Send every feature of the plan, in the new order.']);

        $this->audited($plan, $request, 'display_features_reordered', function () use ($plan, $data) {
            foreach ($data['ids'] as $i => $id) {
                $plan->displayFeatures()->whereKey($id)->first()?->update(['sort_order' => $i]);
            }
        });

        return response()->json(['success' => true, 'message' => 'Order saved.', 'data' => $this->list($plan)]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function list(SubscriptionPlan $plan): array
    {
        return $plan->displayFeatures()->get()->map->toSnapshot()->values()->all();
    }

    private function attrs(DisplayFeatureRequest $request): array
    {
        $v = $request->safe()->except(['id', 'reason']);
        if (array_key_exists('description', $v)) {
            $v['description'] = trim((string) $v['description']) !== '' ? trim($v['description']) : null;
        }
        return $v;
    }

    /** Run $change and log the plan's pricing-page state before / after it. */
    private function audited(SubscriptionPlan $plan, Request $request, string $action, callable $change)
    {
        return DB::transaction(function () use ($plan, $request, $action, $change) {
            $before = AdminPlanController::snapshot($plan);
            $result = $change();
            SubscriptionAuditLog::create([
                'subscription_plan_id' => $plan->id,
                'actor_id'             => $request->user()->id,
                'actor_role'           => 'admin',
                'action'               => $action,
                'reason'               => $request->input('reason'),
                'before'               => $before,
                'after'                => AdminPlanController::snapshot($plan),
            ]);
            return $result;
        });
    }
}
