<?php
// app/Http/Controllers/Admin/AdminPlanController.php
//
// Subscription plan management + platform default commission.
//   GET    /api/admin/subscription-plans                list (with seller counts, feature catalogue)
//   POST   /api/admin/subscription-plans                create
//   PUT    /api/admin/subscription-plans/{id}           update
//   PATCH  /api/admin/subscription-plans/{id}/toggle    activate / deactivate (offered to sellers or not)
//   PATCH  /api/admin/subscription-plans/{id}/default   make this the fallback plan
//   DELETE /api/admin/subscription-plans/{id}           archive (or delete if never used)
//   POST   /api/admin/subscription-plans/{id}/restore   un-archive
//   PATCH  /api/admin/subscription-plans/{id}/recommended "Populaire" badge on the pricing page
//   (one-by-one pricing-page features: AdminPlanDisplayFeatureController)
//   GET    /api/admin/commission-settings               platform default tiers
//   PUT    /api/admin/commission-settings               update them

namespace App\Http\Controllers\Admin;

use App\Enums\PlanCapability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subscriptions\CommissionSettingsRequest;
use App\Http\Requests\Admin\Subscriptions\PlanRequest;
use App\Http\Requests\Admin\Subscriptions\ReasonRequest;
use App\Models\PlanDisplayFeature;
use App\Models\PlatformSetting;
use App\Models\SellerSubscription;
use App\Models\SubscriptionAuditLog;
use App\Models\SubscriptionPlan;
use App\Services\CommissionService;
use App\Services\PricingCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPlanController extends Controller
{
    public function __construct(private CommissionService $commission) {}

    public function index(Request $request): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->when(!$request->boolean('with_archived'), fn($q) => $q->whereNull('archived_at'))
            ->ordered()
            ->with('displayFeatures')
            ->get();

        $counts = SellerSubscription::whereHas('sellerApplication', fn($q) => $q->where('status', 'approved'))
            ->selectRaw("current_plan, SUM(status IN ('active','trial','grace_period','canceled')) AS active, COUNT(*) AS total")
            ->groupBy('current_plan')
            ->get()->keyBy('current_plan');

        return response()->json(['success' => true, 'data' => [
            'plans'    => $plans->map(fn($p) => $this->format($p, $counts[$p->slug] ?? null))->values(),
            'features' => PlanCapability::catalogue(),
            'pricing_page' => [
                'icons'                => PlanDisplayFeature::ICONS,
                'limits'               => SubscriptionPlan::PUBLIC_LIMITS,
                'max_display_features' => PlanDisplayFeature::MAX_PER_PLAN,
            ],
            'commission_default' => $this->commission->rateTable(),
        ]]);
    }

    public function store(PlanRequest $request): JsonResponse
    {
        $plan = DB::transaction(function () use ($request) {
            $plan = SubscriptionPlan::create($this->payload($request) + [
                'slug'          => $request->slug,
                'display_order' => $request->input('display_order', (int) SubscriptionPlan::max('display_order') + 1),
            ]);
            $this->syncDisplayFeatures($plan, $request);
            $this->exclusiveRecommended($plan);
            $this->audit($plan, 'plan_created', $request, null);
            return $plan;
        });

        return response()->json(['success' => true, 'message' => "Plan \"{$plan->name}\" created.", 'data' => $this->format($plan)], 201);
    }

    public function update(PlanRequest $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);
        if ($plan->is_default && $request->has('is_active') && !$request->boolean('is_active')) {
            return response()->json(['success' => false, 'message' => 'The default plan cannot be deactivated.'], 422);
        }

        DB::transaction(function () use ($plan, $request) {
            $before = self::snapshot($plan);
            $plan->update($this->payload($request));
            $this->syncDisplayFeatures($plan, $request);
            $this->exclusiveRecommended($plan);
            $this->audit($plan, 'plan_updated', $request, $before);
        });
        PricingCatalog::flush();

        return response()->json([
            'success' => true,
            'message' => 'Plan updated. Commission changes apply to new orders only.',
            'data'    => $this->format($plan->fresh()),
        ]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);
        if ($plan->is_default && $plan->is_active) {
            return response()->json(['success' => false, 'message' => 'The default plan cannot be deactivated.'], 422);
        }
        if ($plan->isArchived()) {
            return response()->json(['success' => false, 'message' => 'Restore the plan before activating it.'], 422);
        }

        $before = self::snapshot($plan);
        $plan->update(['is_active' => !$plan->is_active]);
        $this->audit($plan, 'plan_updated', $request, $before, $plan->is_active ? 'Activated' : 'Deactivated');

        return response()->json([
            'success' => true,
            'message' => $plan->is_active ? 'Plan activated — sellers can choose it.' : 'Plan deactivated — hidden from sellers; current subscribers keep it.',
            'data'    => $this->format($plan),
        ]);
    }

    public function makeDefault(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);
        if ($plan->isArchived() || !$plan->is_active) {
            return response()->json(['success' => false, 'message' => 'Only an active plan can be the default.'], 422);
        }

        DB::transaction(function () use ($plan, $request) {
            SubscriptionPlan::where('is_default', true)->update(['is_default' => false]);
            $plan->update(['is_default' => true]);
            $this->audit($plan, 'plan_updated', $request, null, 'Set as default plan');
        });

        return response()->json(['success' => true, 'message' => "{$plan->name} is now the default plan.", 'data' => $this->format($plan->fresh())]);
    }

    /** Toggle the "Populaire" badge on the pricing page; at most one plan carries it. */
    public function recommend(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);
        $on   = !$plan->is_recommended;
        if ($on && (!$plan->is_active || $plan->isArchived())) {
            return response()->json(['success' => false, 'message' => 'Only an active plan can be recommended.'], 422);
        }

        DB::transaction(function () use ($plan, $request, $on) {
            $before = self::snapshot($plan);
            $plan->update(['is_recommended' => $on]);
            $this->exclusiveRecommended($plan);
            $this->audit($plan, 'plan_updated', $request, $before, $on ? 'Marked as recommended' : 'No longer recommended');
        });

        return response()->json([
            'success' => true,
            'message' => $on ? "{$plan->name} is now highlighted on the pricing page." : "{$plan->name} is no longer highlighted.",
            'data'    => $this->format($plan->fresh()),
        ]);
    }

    /** Archive — a plan that has (or had) sellers is never hard-deleted. */
    public function destroy(ReasonRequest $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);
        if ($plan->is_default) {
            return response()->json(['success' => false, 'message' => 'The default plan cannot be archived. Make another plan the default first.'], 422);
        }

        $inUse = SellerSubscription::where('current_plan', $plan->slug)->orWhere('pending_plan', $plan->slug)->exists();
        $everUsed = $inUse
            || DB::table('order_items')->where('plan_used', $plan->slug)->exists()
            || DB::table('subscription_plan_changes')->where('from_plan', $plan->slug)->orWhere('to_plan', $plan->slug)->exists()
            || DB::table('subscription_payments')->where('plan', $plan->slug)->exists();

        if (!$everUsed) {
            $this->audit($plan, 'plan_archived', $request, self::snapshot($plan), 'Deleted (never used) — ' . $request->reason);
            $plan->delete();
            return response()->json(['success' => true, 'message' => 'Plan deleted (it was never used).']);
        }

        $before = self::snapshot($plan);
        $plan->update(['archived_at' => now(), 'is_active' => false]);
        $this->audit($plan, 'plan_archived', $request, $before);

        return response()->json([
            'success' => true,
            'message' => $inUse
                ? 'Plan archived. Its current sellers keep it until you move them; nobody new can join.'
                : 'Plan archived.',
            'data'    => $this->format($plan),
        ]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);
        $before = self::snapshot($plan);
        $plan->update(['archived_at' => null]);
        $this->audit($plan, 'plan_restored', $request, $before);

        return response()->json(['success' => true, 'message' => 'Plan restored (inactive — activate it to offer it).', 'data' => $this->format($plan)]);
    }

    // ── Platform default commission ─────────────────────────────────────────

    public function commissionSettings(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->commission->rateTable()]);
    }

    public function updateCommissionSettings(CommissionSettingsRequest $request): JsonResponse
    {
        $before = $this->commission->defaultTable();
        $value  = [
            'tiers' => collect($request->tiers)->sortBy('min')->values()->map(fn($t) => [
                'min'  => (float) $t['min'],
                'max'  => isset($t['max']) && $t['max'] !== '' ? (float) $t['max'] : null,
                'rate' => (float) $t['rate'],
            ])->all(),
            'floor' => (float) $request->floor,
        ];

        DB::transaction(function () use ($value, $request, $before) {
            PlatformSetting::setValue(CommissionService::SETTING_KEY, $value, $request->user()->id);
            SubscriptionAuditLog::create([
                'actor_id'   => $request->user()->id,
                'actor_role' => 'admin',
                'action'     => 'default_commission_updated',
                'reason'     => $request->reason,
                'before'     => $before,
                'after'      => $value,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Default commission updated. It applies to new orders only.',
            'data'    => $this->commission->rateTable(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function payload(PlanRequest $request): array
    {
        $data = $request->safe()->except(['slug', 'features', 'reason', 'capability_display', 'hidden_limits', 'display_features']);
        if (($features = $request->features()) !== null) {
            $data['features'] = $features;
        }
        if ($request->has('capability_display')) {
            $data['capability_display'] = $request->capabilityDisplay();
        }
        if ($request->has('hidden_limits')) {
            $data['hidden_limits'] = $request->hiddenLimits() ?: null;
        }
        return $data;
    }

    /**
     * Replace the plan's pricing-page features with the submitted list, in order.
     * Rows keep their id when sent back; rows left out are deleted.
     */
    private function syncDisplayFeatures(SubscriptionPlan $plan, PlanRequest $request): void
    {
        if (!$request->has('display_features')) return;

        $existing = $plan->displayFeatures()->get()->keyBy('id');
        $kept = [];
        foreach (array_values($request->validated()['display_features'] ?? []) as $i => $row) {
            $description = trim((string) ($row['description'] ?? ''));
            $attrs = [
                'label'       => trim($row['label']),
                'description' => $description !== '' ? $description : null,
                'icon'        => $row['icon'] ?? null,
                'included'    => (bool) ($row['included'] ?? true),
                'highlight'   => (bool) ($row['highlight'] ?? false),
                'sort_order'  => $i,
            ];
            $feature = isset($row['id']) ? $existing->get((int) $row['id']) : null;
            if ($feature) {
                $feature->update($attrs);
            } else {
                $feature = $plan->displayFeatures()->create($attrs);
            }
            $kept[] = $feature->id;
        }
        $plan->displayFeatures()->whereNotIn('id', $kept)->get()->each->delete();
        $plan->unsetRelation('displayFeatures');
    }

    private function exclusiveRecommended(SubscriptionPlan $plan): void
    {
        if ($plan->is_recommended) {
            SubscriptionPlan::where('id', '!=', $plan->id)->where('is_recommended', true)->update(['is_recommended' => false]);
        }
    }

    /** Plan row + its pricing-page features, for the audit log's before / after. */
    public static function snapshot(SubscriptionPlan $plan): array
    {
        return $plan->attributesToArray() + [
            'display_features' => $plan->displayFeatures()->get()->map->toSnapshot()->values()->all(),
        ];
    }

    private function audit(SubscriptionPlan $plan, string $action, Request $request, ?array $before, ?string $reason = null): void
    {
        SubscriptionAuditLog::create([
            'subscription_plan_id' => $plan->exists ? $plan->id : null,
            'actor_id'             => $request->user()->id,
            'actor_role'           => 'admin',
            'action'               => $action,
            'reason'               => $reason ?? $request->input('reason'),
            'before'               => $before,
            'after'                => $plan->exists && ($fresh = $plan->fresh()) ? self::snapshot($fresh) : null,
        ]);
    }

    private function format(SubscriptionPlan $p, $count = null): array
    {
        if ($count === null) {
            $count = SellerSubscription::where('current_plan', $p->slug)
                ->selectRaw("SUM(status IN ('active','trial','grace_period','canceled')) AS active, COUNT(*) AS total")
                ->first();
        }
        $range = $this->commission->rateRangeForPlan($p->slug);

        return $p->attributesToArray() + [
            'display_features' => $p->displayFeatures->map->toSnapshot()->values()->all(),
            'tier_key'         => $p->tierKey(),
            'active_sellers'   => (int) ($count->active ?? 0),
            'total_sellers'    => (int) ($count->total ?? 0),
            'commission_range' => $range,
            'commission_label' => $p->commission_rate !== null
                ? "{$p->commission_rate}% flat"
                : ($range['min'] == $range['max'] ? "{$range['min']}%" : "{$range['min']}–{$range['max']}% by price"),
        ];
    }
}
