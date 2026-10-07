<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\ProfitAlertSetting;
use App\Models\RevenueGoal;
use App\Services\Profit\GoalAlerts;
use App\Services\Profit\ProfitCenter;
use App\Services\Profit\SellerRevenueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Centre de profit (Black Pepper). Routes sit behind seller.feature:black_hub;
 * every query is scoped to auth()->id() — no seller id is ever read from the request.
 *
 *   GET    /api/seller/black/profit-center
 *   POST   /api/seller/black/revenue-goals            set / edit the current or next month's goal
 *   DELETE /api/seller/black/revenue-goals/{month}
 *   PUT    /api/seller/black/profit-center/alerts
 *   GET    /api/seller/black/profit-center/export?month=YYYY-MM   (CSV)
 */
class ProfitCenterController extends Controller
{
    public function __construct(
        private ProfitCenter $center,
        private SellerRevenueService $revenue,
        private GoalAlerts $alerts,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->center->build((int) auth()->id())]);
    }

    /** Months a goal can be set for: this month (edit mid-month) and next month. */
    private function editableMonths(): array
    {
        $now = $this->revenue->now()->startOfMonth();
        return [$now->format('Y-m'), $now->addMonthNoOverflow()->format('Y-m')];
    }

    public function saveGoal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month'         => ['required', 'string', Rule::in($this->editableMonths())],
            'amount'        => 'required|numeric|min:1|max:9999999',
            'orders_target' => 'nullable|integer|min:1|max:100000',
            'net_target'    => 'nullable|numeric|min:1|max:9999999|lte:amount',
            'preset'        => ['nullable', Rule::in(['prudent', 'realistic', 'ambitious', 'custom'])],
        ], [
            'month.in'       => __('profit.goal.month_invalid'),
            'net_target.lte' => __('profit.goal.net_above_sales'),
        ]);

        $sellerId = (int) auth()->id();
        $start    = $this->revenue->monthStart($data['month']);
        $sales    = $this->revenue->salesBetween($sellerId, $start, $start->addMonthNoOverflow())['sales'];

        $goal = RevenueGoal::firstOrNew(['seller_id' => $sellerId, 'month' => $data['month']]);
        $goal->fill([
            'goal_amount'   => round((float) $data['amount'], 3),
            'orders_target' => $data['orders_target'] ?? null,
            'net_target'    => isset($data['net_target']) ? round((float) $data['net_target'], 3) : null,
            'preset'        => $data['preset'] ?? 'custom',
        ]);
        // Thresholds already passed under the new target are on screen: no alert for them.
        // Higher ones (re)open so an edited goal still gets its milestones.
        $goal->milestones_sent = $this->alerts->reached($sales, $goal->goal_amount);
        $goal->save();

        return response()->json([
            'success' => true,
            'message' => __('profit.goal.saved'),
            'data'    => $this->center->goalPublic($goal),
        ]);
    }

    public function deleteGoal(string $month): JsonResponse
    {
        abort_unless(in_array($month, $this->editableMonths(), true), 422, __('profit.goal.month_invalid'));
        RevenueGoal::where('seller_id', auth()->id())->where('month', $month)->delete();
        return response()->json(['success' => true, 'message' => __('profit.goal.deleted')]);
    }

    public function updateAlerts(Request $request): JsonResponse
    {
        $rules = collect(ProfitAlertSetting::TOGGLES)->mapWithKeys(fn($k) => [$k => 'sometimes|boolean'])->all();
        $data  = $request->validate($rules);

        $settings = ProfitAlertSetting::forSeller((int) auth()->id());
        $settings->fill($data)->save();

        return response()->json(['success' => true, 'message' => __('profit.alerts.saved'), 'data' => $settings->toPublic()]);
    }

    /** Monthly report as CSV (Excel-friendly: UTF-8 BOM, ";" separator). */
    public function export(Request $request): StreamedResponse
    {
        $now = $this->revenue->now()->startOfMonth();
        $allowed = collect(range(0, 11))->map(fn($i) => $now->subMonthsNoOverflow($i)->format('Y-m'))->all();
        $month = $request->validate(['month' => ['nullable', Rule::in($allowed)]])['month'] ?? $now->format('Y-m');

        $sellerId = (int) auth()->id();
        $start  = $this->revenue->monthStart($month);
        $totals = $this->revenue->monthly($sellerId, $start, $start->addMonthNoOverflow())[$month];
        $goal   = RevenueGoal::where('seller_id', $sellerId)->where('month', $month)->first();
        $lines  = $this->revenue->orderLines($sellerId, $start);
        $num    = fn($v) => number_format((float) $v, 3, ',', '');

        return response()->streamDownload(function () use ($month, $totals, $goal, $lines, $num) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $row = fn(array $r) => fputcsv($out, $r, ';');

            $row([__('profit.export.title'), $month]);
            $row([]);
            foreach (['gross', 'refunds', 'commission', 'shipping', 'ads', 'net', 'sales', 'delivered'] as $k) {
                $row([__("profit.export.{$k}"), $num($totals[$k])]);
            }
            $row([__('profit.export.ads_credit'), $num($totals['ads_credit'])]);
            $row([__('profit.export.orders'), $totals['orders']]);
            if ($goal) {
                $row([__('profit.export.goal'), $num($goal->goal_amount)]);
                $row([__('profit.export.achieved'), round($totals['sales'] / max(0.001, $goal->goal_amount) * 100, 1) . ' %']);
            }
            $row([]);
            $row(array_map(fn($k) => __("profit.export.col.{$k}"), ['order', 'date', 'status', 'amount', 'commission', 'shipping', 'net', 'payout']));
            foreach ($lines as $l) {
                $row([$l['order'], $l['date'], __("profit.export.status.{$l['status']}"), $num($l['amount']),
                      $num($l['commission']), $num($l['shipping']), $num($l['net']), $l['payout']]);
            }
            fclose($out);
        }, "centre-de-profit-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
