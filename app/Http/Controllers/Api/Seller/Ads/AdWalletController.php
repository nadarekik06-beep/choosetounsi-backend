<?php

namespace App\Http\Controllers\Api\Seller\Ads;

use App\Exceptions\Ads\AdRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Resources\Ads\AdTopUpResource;
use App\Http\Resources\Ads\AdWalletResource;
use App\Http\Resources\Ads\AdWalletTransactionResource;
use App\Models\AdTopUp;
use App\Models\AdWalletTransaction;
use App\Services\Ads\AdSettings;
use App\Services\Ads\AdWalletService;
use App\Services\Ads\Payments\AdTopUpGateways;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Seller ad wallet.
 *
 *   GET  /api/seller/ads/wallet                 balance, free credit (+ expiry), gateways, min top-up
 *   GET  /api/seller/ads/wallet/transactions    ledger (paginated)
 *   GET  /api/seller/ads/wallet/top-ups         top-up intents
 *   POST /api/seller/ads/wallet/top-up          {amount, gateway, reference?}
 *   POST /api/ads/top-ups/callback/{gateway}    (public) hosted-page gateway confirmation
 */
class AdWalletController extends Controller
{
    public function __construct(
        private AdWalletService $wallets,
        private AdTopUpGateways $gateways,
        private AdSettings $settings,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => (new AdWalletResource($this->wallets->walletFor($request->user()->id)))->resolve() + [
                'min_top_up' => $this->settings->float('min_top_up'),
                'gateways'   => $this->gateways->availableKeys(),
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $page = AdWalletTransaction::where('seller_id', $request->user()->id)
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return response()->json([
            'success' => true,
            'data'    => AdWalletTransactionResource::collection($page->getCollection()),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function topUps(Request $request): JsonResponse
    {
        $topUps = AdTopUp::where('seller_id', $request->user()->id)->orderByDesc('id')->limit(50)->get();
        return response()->json(['success' => true, 'data' => AdTopUpResource::collection($topUps)]);
    }

    public function topUp(Request $request): JsonResponse
    {
        $min  = $this->settings->float('min_top_up');
        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'max:100000'],
            'gateway'   => ['required', 'string', Rule::in($this->gateways->availableKeys())],
            'reference' => ['nullable', 'string', 'max:100', 'required_if:gateway,manual'],
        ]);
        $amount = round((float) $data['amount'], 3);
        if ($amount + 0.0005 < $min) {
            throw AdRuleViolation::make('top_up_below_min', ['min' => $min], ['min' => number_format($min, 3)]);
        }

        $gateway = $this->gateways->get($data['gateway']);
        try {
            $topUp = AdTopUp::create([
                'seller_id' => $request->user()->id,
                'amount'    => $amount,
                'gateway'   => $gateway->key(),
                'status'    => AdTopUp::STATUS_PENDING,
                'reference' => $data['reference'] ?? null,
            ]);
        } catch (QueryException $e) {
            // The same transfer reference submitted twice for this gateway.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw AdRuleViolation::make('top_up_not_pending', [], [], 409);
            }
            throw $e;
        }

        $start = $gateway->start($topUp, $data);

        return response()->json([
            'success' => true,
            'data'    => [
                'top_up'       => new AdTopUpResource($topUp->fresh()),
                'status'       => $start['status'],
                'redirect_url' => $start['redirect_url'] ?? null,
                'instructions' => $start['instructions'] ?? null,
                'wallet'       => new AdWalletResource($this->wallets->walletFor($request->user()->id)),
            ],
        ], 201);
    }

    /** Server-to-server confirmation from a hosted-page gateway. Replays are no-ops. */
    public function callback(Request $request, string $gateway): JsonResponse
    {
        $result = $this->gateways->get($gateway)->handleCallback($request);
        if (!$result) {
            return response()->json(['success' => false], 400);
        }

        $topUp = AdTopUp::where('gateway', $gateway)->findOrFail($result['top_up_id']);
        $topUp = $this->wallets->settleTopUp($topUp, (bool) $result['paid'], $result['reference'] ?? null, ['callback' => true]);

        return response()->json(['success' => true, 'status' => $topUp->status]);
    }
}
