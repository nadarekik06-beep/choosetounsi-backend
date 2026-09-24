<?php
// app/Http/Controllers/Admin/AdminSubscriptionController.php
//
// Seller subscription management (all under /api/admin/subscriptions):
//   GET    /                                  list with search / filters / sorting
//   GET    /stats                             KPI cards + 12-month chart
//   GET    /{sellerId}                        detail + full history timeline
//   POST   /{sellerId}/assign-plan            assign any plan now (upgrade / downgrade)
//   POST   /{sellerId}/force-plan             legacy alias of assign-plan
//   POST   /{sellerId}/end-date               extend / shorten the current period
//   POST   /{sellerId}/free-days              add free days
//   POST   /{sellerId}/trial                  start a trial
//   POST   /{sellerId}/suspend                suspend (reason required)
//   POST   /{sellerId}/reinstate              reactivate (reason required)
//   POST   /{sellerId}/cancel                 cancel now or at period end (reason required)
//   PUT    /{sellerId}/commission-override    set a per-seller commission rate
//   DELETE /{sellerId}/commission-override    remove it

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subscriptions\AssignPlanRequest;
use App\Http\Requests\Admin\Subscriptions\ChangeEndDateRequest;
use App\Http\Requests\Admin\Subscriptions\CommissionOverrideRequest;
use App\Http\Requests\Admin\Subscriptions\GrantFreeDaysRequest;
use App\Http\Requests\Admin\Subscriptions\ReasonRequest;
use App\Http\Requests\Admin\Subscriptions\StartTrialRequest;
use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\SubscriptionAuditLog;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\CommissionService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSubscriptionController extends Controller
{
    private const EXPIRING_DAYS = 7;

    public function __construct(
        private SubscriptionService $subscriptionService,
        private CommissionService $commission
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // LIST
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = SellerSubscription::query()
            ->with(['user:id,name,email,is_active', 'sellerApplication:id,user_id,business_name,status'])
            ->whereHas('sellerApplication', fn($q) => $q->where('status', 'approved'));

        if ($plan = $request->query('plan')) {
            $query->where('current_plan', $plan);
        }

        switch ($request->query('status')) {
            case 'active':        $query->where('status', 'active'); break;
            case 'trial':         $query->where('status', 'trial'); break;
            case 'grace_period':  $query->where('status', 'grace_period'); break;
            case 'expired':       $query->where('status', 'expired'); break;
            case 'suspended':     $query->where('status', 'suspended'); break;
            case 'cancelled':     $query->where('status', 'canceled'); break;
            case 'expiring_soon': $this->scopeExpiringSoon($query); break;
            case 'has_override':  $query->whereNotNull('commission_override'); break;
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                  ->orWhereHas('sellerApplication', fn($a) => $a->where('business_name', 'like', "%{$search}%"));
            });
        }

        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        switch ($request->query('sort')) {
            case 'seller':
                $query->orderBy(
                    \App\Models\User::select('name')->whereColumn('users.id', 'seller_subscriptions.user_id'),
                    $dir
                );
                break;
            case 'plan':     $query->orderBy('current_plan', $dir); break;
            case 'status':   $query->orderBy('status', $dir); break;
            case 'end_date': $query->orderByRaw('billing_cycle_end IS NULL')->orderBy('billing_cycle_end', $dir); break;
            default:         $query->orderBy('updated_at', $dir);
        }

        $paginated = $query->paginate(min((int) $request->query('per_page', 20), 100));

        $productCounts = $this->productCounts($paginated->getCollection()->pluck('user_id')->all());

        $paginated->getCollection()->transform(fn($sub) => $this->format($sub, $productCounts[$sub->user_id] ?? null));

        return response()->json(['success' => true, 'data' => $paginated]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // KPIs + chart
    // ─────────────────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $approved = fn() => SellerSubscription::whereHas('sellerApplication', fn($q) => $q->where('status', 'approved'));
        $plans    = SubscriptionPlan::orderBy('display_order')->get()->keyBy('slug');

        // Active = seller currently has the plan's features
        $perPlan = $approved()
            ->whereIn('status', ['active', 'trial', 'grace_period', 'canceled'])
            ->selectRaw('current_plan, COUNT(*) AS n')
            ->groupBy('current_plan')
            ->pluck('n', 'current_plan');

        // Monthly recurring revenue from paying subscriptions (yearly / 12)
        $mrr = 0.0;
        $approved()->whereIn('status', ['active', 'grace_period', 'canceled'])
            ->get(['current_plan', 'billing_period', 'pending_plan', 'status'])
            ->each(function ($s) use (&$mrr, $plans) {
                $p = $plans[$s->current_plan] ?? null;
                if (!$p || $p->isFree()) return;
                if ($s->status === 'canceled' && $s->pending_plan) return; // won't renew
                $mrr += $s->billing_period === 'yearly' && $p->price_yearly !== null
                    ? $p->price_yearly / 12
                    : $p->price_monthly;
            });

        $monthStart = now()->startOfMonth();
        $commissionThisMonth = (float) DB::table('seller_orders')
            ->where('created_at', '>=', $monthStart)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->sum('commission_amount');
        $revenueThisMonth = (float) SubscriptionPayment::where('status', 'succeeded')
            ->where('created_at', '>=', $monthStart)->sum('amount');

        // 12-month chart
        $from = now()->subMonths(11)->startOfMonth();
        $subRevenue = SubscriptionPayment::where('status', 'succeeded')->where('created_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(amount) AS total")->groupBy('m')->pluck('total', 'm');
        $commission = DB::table('seller_orders')->where('created_at', '>=', $from)->whereNotIn('status', ['cancelled', 'refunded'])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(commission_amount) AS total")->groupBy('m')->pluck('total', 'm');
        $newPaid = DB::table('subscription_plan_changes')->where('effective_at', '>=', $from)
            ->whereIn('change_type', ['upgrade', 'admin_force'])
            ->whereIn('to_plan', $plans->filter(fn($p) => !$p->isFree())->keys())
            ->selectRaw("DATE_FORMAT(effective_at, '%Y-%m') AS m, COUNT(*) AS n")->groupBy('m')->pluck('n', 'm');

        $chart = [];
        for ($d = $from->copy(); $d <= now(); $d->addMonth()) {
            $k = $d->format('Y-m');
            $chart[] = [
                'month'                => $k,
                'label'                => $d->format('M y'),
                'subscription_revenue' => round((float) ($subRevenue[$k] ?? 0), 3),
                'commission'           => round((float) ($commission[$k] ?? 0), 3),
                'new_paid'             => (int) ($newPaid[$k] ?? 0),
            ];
        }

        return response()->json(['success' => true, 'data' => [
            'per_plan' => $plans->map(fn($p) => [
                'slug'  => $p->slug,
                'name'  => $p->name,
                'color' => $p->badge_color,
                'count' => (int) ($perPlan[$p->slug] ?? 0),
                'archived' => $p->isArchived(),
            ])->values(),
            'trials'                => $approved()->where('status', 'trial')->count(),
            'expiring_this_week'    => $this->scopeExpiringSoon($approved())->count(),
            'grace_period'          => $approved()->where('status', 'grace_period')->count(),
            'expired'               => $approved()->where('status', 'expired')->count(),
            'suspended'             => $approved()->where('status', 'suspended')->count(),
            'overrides'             => $approved()->whereNotNull('commission_override')->count(),
            'mrr'                   => round($mrr, 3),
            'revenue_this_month'    => round($revenueThisMonth, 3),
            'commission_this_month' => round($commissionThisMonth, 3),
            'chart'                 => $chart,
        ]]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DETAIL
    // ─────────────────────────────────────────────────────────────────────────

    public function show(int $sellerId): JsonResponse
    {
        $sub = $this->resolve($sellerId);
        if ($sub instanceof JsonResponse) return $sub;

        return response()->json([
            'success' => true,
            'data'    => [
                'subscription' => $this->format($sub, $this->productCounts([$sub->user_id])[$sub->user_id] ?? null),
                'history'      => $this->timeline($sub),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ACTIONS
    // ─────────────────────────────────────────────────────────────────────────

    public function assignPlan(AssignPlanRequest $request, int $sellerId): JsonResponse
    {
        return $this->act($sellerId, fn($sub) => $this->subscriptionService->assignPlan(
            $sub, $request->plan, $request->user(), $request->reason,
            $request->input('billing_period', 'monthly'),
            $request->end_date ? Carbon::parse($request->end_date) : null
        ), 'Plan assigned.');
    }

    public function changeEndDate(ChangeEndDateRequest $request, int $sellerId): JsonResponse
    {
        return $this->act($sellerId, fn($sub) => $this->subscriptionService->changeEndDate(
            $sub, Carbon::parse($request->end_date), $request->user(), $request->reason
        ), 'End date updated.');
    }

    public function grantFreeDays(GrantFreeDaysRequest $request, int $sellerId): JsonResponse
    {
        return $this->act($sellerId, fn($sub) => $this->subscriptionService->grantFreeDays(
            $sub, (int) $request->days, $request->user(), $request->reason
        ), "{$request->days} free days granted.");
    }

    public function startTrial(StartTrialRequest $request, int $sellerId): JsonResponse
    {
        return $this->act($sellerId, fn($sub) => $this->subscriptionService->startTrial(
            $sub, $request->plan, (int) $request->days, $request->user(), $request->reason
        ), 'Trial started.');
    }

    public function suspend(ReasonRequest $request, int $sellerId): JsonResponse
    {
        return $this->act($sellerId, fn($sub) => $this->subscriptionService->suspend(
            $sub, $request->user(), $request->reason
        ), 'Subscription suspended.');
    }

    public function reinstate(ReasonRequest $request, int $sellerId): JsonResponse
    {
        return $this->act($sellerId, fn($sub) => $this->subscriptionService->reactivate(
            $sub, $request->user(), $request->reason
        ), 'Subscription reactivated.');
    }

    public function cancel(ReasonRequest $request, int $sellerId): JsonResponse
    {
        return $this->act($sellerId, fn($sub) => $this->subscriptionService->cancel(
            $sub, $request->user(), $request->reason, $request->boolean('immediate')
        ), 'Subscription cancelled.');
    }

    public function setCommissionOverride(CommissionOverrideRequest $request, int $sellerId): JsonResponse
    {
        return $this->act($sellerId, fn($sub) => $this->subscriptionService->setCommissionOverride(
            $sub, (float) $request->rate,
            $request->expires_at ? Carbon::parse($request->expires_at) : null,
            $request->user(), $request->reason
        ), 'Commission override saved. It applies to new orders only.');
    }

    public function removeCommissionOverride(ReasonRequest $request, int $sellerId): JsonResponse
    {
        return $this->act($sellerId, fn($sub) => $this->subscriptionService->removeCommissionOverride(
            $sub, $request->user(), $request->reason
        ), 'Commission override removed.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function act(int $sellerId, \Closure $action, string $message): JsonResponse
    {
        $sub = $this->resolve($sellerId);
        if ($sub instanceof JsonResponse) return $sub;

        try {
            $sub = $action($sub);
        } catch (\InvalidArgumentException | \LogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Plan not found.'], 422);
        }

        $sub = $sub->fresh();
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $this->format($sub, $this->productCounts([$sub->user_id])[$sub->user_id] ?? null),
        ]);
    }

    /** Subscription for an approved seller (created on the fly if missing). */
    private function resolve(int $sellerId): SellerSubscription|JsonResponse
    {
        $app = SellerApplication::where('user_id', $sellerId)->where('status', 'approved')->latest()->first();
        if (!$app) {
            return response()->json(['success' => false, 'message' => 'Approved seller not found.'], 404);
        }
        return $this->subscriptionService->getOrCreateSubscription($app)->load(['user', 'sellerApplication']);
    }

    /** Visible vs plan-limit-hidden products per seller. */
    private function productCounts(array $sellerIds)
    {
        return DB::table('products')
            ->whereIn('seller_id', $sellerIds)
            ->whereNull('deleted_at')
            ->selectRaw("seller_id, SUM(hidden_reason IS NULL OR hidden_reason <> 'over_plan_limit') AS visible, SUM(hidden_reason = 'over_plan_limit') AS hidden")
            ->groupBy('seller_id')
            ->get()->keyBy('seller_id');
    }

    private function scopeExpiringSoon($query)
    {
        return $query->whereIn('status', ['active', 'trial', 'canceled'])
            ->whereNotNull('billing_cycle_end')
            ->whereBetween('billing_cycle_end', [today()->toDateString(), today()->addDays(self::EXPIRING_DAYS)->toDateString()]);
    }

    private function planMeta(?string $slug): ?array
    {
        if (!$slug) return null;
        $p = SubscriptionPlan::forSlug($slug);
        return [
            'slug'     => $p->slug,
            'name'     => $p->name,
            'color'    => $p->badge_color,
            'tier_key' => $p->tierKey(),
            'is_free'  => $p->isFree(),
            'archived' => $p->isArchived(),
        ];
    }

    private function format(SellerSubscription $sub, $productCount = null): array
    {
        $sub->loadMissing(['user', 'sellerApplication']);
        $plan = SubscriptionPlan::forSlug($sub->current_plan);

        $expiringSoon = in_array($sub->status, ['active', 'trial', 'canceled'], true)
            && $sub->billing_cycle_end
            && $sub->billing_cycle_end->between(today(), today()->addDays(self::EXPIRING_DAYS));

        return [
            'id'                    => $sub->id,
            'user_id'               => $sub->user_id,
            'seller_name'           => $sub->user?->name,
            'seller_email'          => $sub->user?->email,
            'business_name'         => $sub->sellerApplication?->business_name,
            'current_plan'          => $sub->current_plan,
            'plan'                  => $this->planMeta($sub->current_plan),
            'pending_plan'          => $sub->pending_plan,
            'pending'               => $this->planMeta($sub->pending_plan),
            'status'                => $sub->status,
            'status_label'          => $sub->status_label,
            'expiring_soon'         => $expiringSoon,
            'billing_period'        => $sub->billing_period,
            'billing_cycle_start'   => $sub->billing_cycle_start?->format('Y-m-d'),
            'billing_cycle_end'     => $sub->billing_cycle_end?->format('Y-m-d'),
            'days_remaining'        => $sub->daysRemainingInCycle(),
            'trial_ends_at'         => $sub->trial_ends_at?->toISOString(),
            'grace_period_ends_at'  => $sub->grace_period_ends_at?->toISOString(),
            'has_pending_downgrade' => $sub->hasPendingDowngrade(),
            'last_payment_at'       => $sub->last_payment_at?->toISOString(),
            'suspended_at'          => $sub->suspended_at?->toISOString(),
            'canceled_at'           => $sub->canceled_at?->toISOString(),
            'cancel_reason'         => $sub->cancel_reason,
            'admin_note'            => $sub->admin_note,
            'max_products'          => $plan->max_products,
            'products'              => $productCount ? [
                'visible' => (int) $productCount->visible,
                'hidden'  => (int) $productCount->hidden,
            ] : null,
            'commission'            => $this->commission->effectiveSummary($sub) + [
                'override'            => $sub->commission_override,
                'override_expires_at' => $sub->commission_override_expires_at?->toISOString(),
                'override_reason'     => $sub->commission_override_reason,
                'override_active'     => $sub->activeCommissionOverride() !== null,
            ],
        ];
    }

    /**
     * Full history: audit log (all actions since it exists) + legacy plan
     * changes not linked to an audit entry + payments. Newest first.
     */
    private function timeline(SellerSubscription $sub): array
    {
        $logs = SubscriptionAuditLog::with('actor:id,name,role')
            ->where('seller_subscription_id', $sub->id)
            ->where('action', '!=', 'expiry_reminder')
            ->latest('id')->limit(100)->get();

        $linked = $logs->pluck('plan_change_id')->filter()->all();

        $events = $logs->map(fn($l) => [
            'kind'       => 'audit',
            'action'     => $l->action,
            'label'      => $l->label,
            'reason'     => $l->reason,
            'before'     => $l->before,
            'after'      => $l->after,
            'actor'      => $l->actor ? ['id' => $l->actor->id, 'name' => $l->actor->name, 'role' => $l->actor_role] : ['name' => 'System', 'role' => 'system'],
            'at'         => $l->created_at?->toISOString(),
        ]);

        $changes = $sub->planChanges()->with('changedBy:id,name,role')
            ->whereNotIn('id', $linked)->limit(50)->get()
            ->map(fn($c) => [
                'kind'   => 'plan_change',
                'action' => $c->change_type,
                'label'  => $c->change_type_label . ': ' . SubscriptionPlan::forSlug($c->from_plan)->name . ' → ' . SubscriptionPlan::forSlug($c->to_plan)->name,
                'reason' => $c->reason,
                'amount' => (float) $c->amount_charged,
                'actor'  => $c->changedBy ? ['id' => $c->changedBy->id, 'name' => $c->changedBy->name, 'role' => $c->changedBy->role] : ['name' => 'System', 'role' => 'system'],
                'at'     => $c->effective_at?->toISOString(),
            ]);

        $payments = SubscriptionPayment::where('user_id', $sub->user_id)->latest()->limit(50)->get()
            ->map(fn($p) => [
                'kind'   => 'payment',
                'action' => 'payment_' . $p->status,
                'label'  => 'Payment ' . $p->status . ' — ' . SubscriptionPlan::forSlug($p->plan)->name,
                'reason' => $p->card_last4 ? "Card •••• {$p->card_last4}" : null,
                'amount' => (float) $p->amount,
                'actor'  => ['name' => $sub->user?->name, 'role' => 'seller'],
                'at'     => $p->created_at?->toISOString(),
            ]);

        return $events->concat($changes)->concat($payments)
            ->sortByDesc('at')->values()->all();
    }
}
