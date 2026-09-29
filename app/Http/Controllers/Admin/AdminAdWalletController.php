<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ads\AdTopUpResource;
use App\Http\Resources\Ads\AdWalletResource;
use App\Http\Resources\Ads\AdWalletTransactionResource;
use App\Models\AdTopUp;
use App\Models\User;
use App\Services\Ads\AdClock;
use App\Services\Ads\AdWalletService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin side of the ad wallets (UI comes with the admin sponsoring section).
 *
 *   POST /api/admin/ads/wallets/{seller}/adjust    {balance_delta, credit_delta, note, credit_expires_at?}
 *   GET  /api/admin/ads/top-ups                    ?status=pending
 *   POST /api/admin/ads/top-ups/{id}/confirm       manual transfer received → wallet credited (once)
 *   POST /api/admin/ads/top-ups/{id}/reject
 *
 * Confirm / reject only act on a pending top-up; repeating them returns the
 * top-up as it is (no second wallet transaction).
 */
class AdminAdWalletController extends Controller
{
    public function __construct(private AdWalletService $wallets) {}

    public function adjust(Request $request, int $seller): JsonResponse
    {
        $data = $request->validate([
            'balance_delta'     => ['nullable', 'numeric', 'between:-100000,100000'],
            'credit_delta'      => ['nullable', 'numeric', 'between:-100000,100000'],
            'note'              => ['required', 'string', 'max:500'],
            'credit_expires_at' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $user = User::where('id', $seller)->where('role', 'seller')->firstOrFail();

        $expires = isset($data['credit_expires_at'])
            ? AdClock::toStorage(Carbon::parse($data['credit_expires_at'], AdClock::timezone())->endOfDay())
            : null;

        $tx = $this->wallets->adminAdjust(
            $user->id, (float) ($data['balance_delta'] ?? 0), (float) ($data['credit_delta'] ?? 0),
            $data['note'], $request->user()->id, $expires
        );

        return response()->json(['success' => true, 'data' => [
            'transaction' => new AdWalletTransactionResource($tx),
            'wallet'      => new AdWalletResource($this->wallets->walletFor($user->id)),
        ]]);
    }

    public function topUps(Request $request): JsonResponse
    {
        $page = AdTopUp::with('seller:id,name,email')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return response()->json([
            'success' => true,
            'data'    => AdTopUpResource::collection($page->getCollection()),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        return $this->settle($request, $id, true);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->settle($request, $id, false);
    }

    private function settle(Request $request, int $id, bool $paid): JsonResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $topUp = AdTopUp::findOrFail($id);
        $wasPending = $topUp->isPending();

        $topUp = $this->wallets->settleTopUp(
            $topUp, $paid, null, array_filter(['admin_note' => $request->input('note')]), $request->user()->id,
            AdTopUp::STATUS_FAILED
        );

        return response()->json(['success' => true, 'data' => [
            'top_up'          => new AdTopUpResource($topUp->load('seller:id,name,email')),
            'already_settled' => !$wasPending,
        ]]);
    }
}
