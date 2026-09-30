<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentRequestResource;
use App\Models\PaymentRequest;
use App\Services\Payments\ManualPaymentSettings;
use App\Services\Payments\PaymentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Seller side of the manual (WhatsApp) payments.
 *
 *   GET  /api/seller/payment-requests              own requests (?type=wallet_topup|plan_upgrade) + method config
 *   GET  /api/seller/payment-requests/{id}
 *   POST /api/seller/payment-requests/wallet-top-up {amount}              (sponsoring plans only)
 *   POST /api/seller/payment-requests/plan-upgrade  {plan, billing_period?}
 *   POST /api/seller/payment-requests/{id}/cancel   pending only
 *
 * Creating returns the request with `whatsapp_url` — the chat with the
 * ChooseTounsi number, message pre-filled.
 */
class SellerPaymentRequestController extends Controller
{
    public function __construct(private PaymentRequestService $service, private ManualPaymentSettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['type' => ['nullable', Rule::in(PaymentRequest::TYPES)]]);
        $rows = PaymentRequest::where('seller_id', $request->user()->id)
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'success' => true,
            'data'    => PaymentRequestResource::collection($rows),
            'config'  => $this->config(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $r = PaymentRequest::where('seller_id', $request->user()->id)->findOrFail($id);
        return response()->json(['success' => true, 'data' => new PaymentRequestResource($r)]);
    }

    public function topUp(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.001', 'max:1000000']]);
        $r = $this->service->requestTopUp($request->user(), (float) $data['amount']);
        return response()->json(['success' => true, 'data' => new PaymentRequestResource($r)], 201);
    }

    public function planUpgrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan'           => ['required', 'string', 'max:30'],
            'billing_period' => ['nullable', Rule::in(['monthly', 'yearly'])],
        ]);
        $r = $this->service->requestPlanUpgrade($request->user(), $data['plan'], $data['billing_period'] ?? 'monthly');
        return response()->json(['success' => true, 'data' => new PaymentRequestResource($r)], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $r = PaymentRequest::where('seller_id', $request->user()->id)->findOrFail($id);
        $r = $this->service->cancel($r, $request->user());
        return response()->json(['success' => true, 'data' => new PaymentRequestResource($r)]);
    }

    private function config(): array
    {
        $cfg = $this->settings->all();
        return [
            'enabled'         => $cfg['whatsapp_enabled'],
            'whatsapp_number' => $cfg['whatsapp_number'],
            'min_top_up'      => $cfg['min_top_up'],
            'max_top_up'      => $cfg['max_top_up'],
        ];
    }
}
