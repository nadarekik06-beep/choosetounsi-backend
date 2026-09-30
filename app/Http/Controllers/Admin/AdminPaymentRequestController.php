<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentRequestResource;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Payments\ManualPaymentSettings;
use App\Services\Payments\PaymentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin "Demandes de paiement" (manual WhatsApp payments).
 *
 *   GET  /api/admin/payment-requests                 ?type&status&date_from&date_to&search&page
 *   GET  /api/admin/payment-requests/pending-count   menu badge
 *   GET  /api/admin/payment-requests/{id}            with the action log
 *   POST /api/admin/payment-requests/{id}/approve    {payment_method, amount_received?, transaction_reference?, note?}
 *   POST /api/admin/payment-requests/{id}/reject     {reason}
 *   POST /api/admin/payment-requests/direct/wallet-top-up  {seller_id, amount, payment_method, transaction_reference?, note?}
 *   POST /api/admin/payment-requests/direct/plan-change    {seller_id, plan, billing_period, payment_method, amount?, transaction_reference?, note?}
 *   GET|PUT /api/admin/payment-requests/settings
 *
 * Approve / reject act once: a second call (double click, second admin) gets 409.
 */
class AdminPaymentRequestController extends Controller
{
    public function __construct(private PaymentRequestService $service, private ManualPaymentSettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type'      => ['nullable', Rule::in(PaymentRequest::TYPES)],
            'status'    => ['nullable', Rule::in(PaymentRequest::STATUSES)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d'],
            'search'    => ['nullable', 'string', 'max:100'],
            'seller_id' => ['nullable', 'integer'],
        ]);

        $page = PaymentRequest::with('decidedBy:id,name')
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('seller_id'), fn ($q, $v) => $q->where('seller_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when(trim((string) $request->query('search')), function ($q, $s) {
                $digits = preg_replace('/\D+/', '', $s);
                $q->where(function ($w) use ($s, $digits) {
                    $w->where('reference', 'like', "%{$s}%")
                        ->orWhere('store_name', 'like', "%{$s}%")
                        ->orWhere('seller_name', 'like', "%{$s}%")
                        ->orWhere('seller_email', 'like', "%{$s}%")
                        ->orWhere('seller_phone', 'like', "%{$s}%");
                    if (strlen($digits) >= 3) {
                        $w->orWhereRaw("REPLACE(REPLACE(seller_phone, ' ', ''), '+', '') LIKE ?", ["%{$digits}%"]);
                    }
                });
            })
            ->orderByRaw("status = 'pending' DESC")->orderByDesc('id')
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($r) => $this->present($r)),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts'  => PaymentRequest::where('status', PaymentRequest::STATUS_PENDING)
                ->selectRaw('type, COUNT(*) n')->groupBy('type')->pluck('n', 'type'),
        ]);
    }

    public function pendingCount(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'count' => PaymentRequest::where('status', PaymentRequest::STATUS_PENDING)->count(),
        ]]);
    }

    public function show(int $id): JsonResponse
    {
        $r = PaymentRequest::with(['decidedBy:id,name', 'logs.actor:id,name'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $this->present($r)]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'payment_method'        => ['required', Rule::in(PaymentRequest::METHODS)],
            'amount_received'       => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'note'                  => ['nullable', 'string', 'max:1000'],
        ]);
        $r = $this->service->approve(PaymentRequest::findOrFail($id), $request->user(), $data);
        return $this->detail($r);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $r = $this->service->reject(PaymentRequest::findOrFail($id), $request->user(), trim($data['reason']));
        return $this->detail($r);
    }

    public function directTopUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_id'             => ['required', 'integer'],
            'amount'                => ['required', 'numeric', 'min:0.001', 'max:1000000'],
            'payment_method'        => ['required', Rule::in(PaymentRequest::METHODS)],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'note'                  => ['nullable', 'string', 'max:1000'],
        ]);
        $seller = User::where('role', 'seller')->findOrFail($data['seller_id']);
        return $this->detail($this->service->directTopUp($seller, $request->user(), $data), 201);
    }

    public function directPlanChange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_id'             => ['required', 'integer'],
            'plan'                  => ['required', 'string', 'max:30'],
            'billing_period'        => ['required', Rule::in(['monthly', 'yearly'])],
            'amount'                => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'payment_method'        => ['required', Rule::in(PaymentRequest::METHODS)],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'note'                  => ['nullable', 'string', 'max:1000'],
        ]);
        $seller = User::where('role', 'seller')->findOrFail($data['seller_id']);
        return $this->detail($this->service->directPlanChange($seller, $request->user(), $data), 201);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'values'   => $this->settings->all(),
            'defaults' => $this->settings->defaults(),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'whatsapp_enabled'      => ['sometimes', 'boolean'],
            'whatsapp_number'       => ['sometimes', 'string', 'max:30', 'regex:/^[+\d\s().-]+$/'],
            'template_wallet_topup' => ['sometimes', 'string', 'min:10', 'max:2000'],
            'template_plan_upgrade' => ['sometimes', 'string', 'min:10', 'max:2000'],
            'min_top_up'            => ['sometimes', 'numeric', 'min:1', 'max:100000'],
            'max_top_up'            => ['sometimes', 'numeric', 'min:1', 'max:1000000'],
        ]);
        if (isset($data['whatsapp_number']) && !\App\Services\Payments\WhatsApp::normalizePhone($data['whatsapp_number'])) {
            return response()->json(['success' => false, 'message' => 'Invalid WhatsApp number.', 'errors' => ['whatsapp_number' => ['Invalid WhatsApp number.']]], 422);
        }
        $min = (float) ($data['min_top_up'] ?? $this->settings->get('min_top_up'));
        $max = (float) ($data['max_top_up'] ?? $this->settings->get('max_top_up'));
        if ($max < $min) {
            return response()->json(['success' => false, 'message' => 'The maximum top-up must be at least the minimum.', 'errors' => ['max_top_up' => ['The maximum top-up must be at least the minimum.']]], 422);
        }

        $this->settings->set($data, $request->user()->id);
        return $this->settings();
    }

    private function detail(PaymentRequest $r, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->present($r->load(['decidedBy:id,name', 'logs.actor:id,name']))], $status);
    }

    private function present(PaymentRequest $r): array
    {
        return (new PaymentRequestResource($r))->forAdmin()->resolve(request());
    }
}
