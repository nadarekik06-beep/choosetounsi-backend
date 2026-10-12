<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Delivery\DeliverySettings;
use App\Services\Intigo\IntigoClient;
use App\Services\Intigo\IntigoException;
use App\Services\Intigo\IntigoRegions;
use App\Services\Intigo\IntigoStatusMap;
use App\Services\Intigo\ParcelCanceller;
use App\Services\Intigo\ParcelPayload;
use App\Services\Intigo\ParcelSender;
use App\Support\Millimes;
use App\Support\ParcelSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin side of the Intigo integration.
 *
 *   GET  /api/admin/intigo                               mode, setup checklist, failed sends, parcels to check, recent logs
 *   GET  /api/admin/intigo/logs/{id}                     one request / answer
 *   GET  /api/admin/intigo/pickup-addresses              the account's pickup addresses (read-only call)
 *   PUT  /api/admin/sellers/{id}/intigo-pickup           {index|null, label?}
 *   GET  /api/admin/seller-orders/{id}/intigo            parcel: nid, sync, error, carrier status + history, logs
 *   POST /api/admin/seller-orders/{id}/intigo/retry      send again (same no-duplicate rules)
 *   POST /api/admin/seller-orders/{id}/intigo/cancel     cancel at Intigo (pickup phase only)
 *   POST /api/admin/seller-orders/{id}/intigo/dismiss    clear the "needs attention" flag
 *   PUT  /api/admin/seller-orders/{id}/parcel-size       {size: S|L|XL} before the parcel is sent
 */
class IntigoController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $failed = SellerOrder::with(['order:id,order_number,recipient_name', 'seller:id,name'])
            ->where('intigo_sync_status', ParcelSender::FAILED)
            ->whereNotIn('status', ['cancelled'])
            ->orderByDesc('updated_at')->limit(100)->get();

        $attention = SellerOrder::with(['order:id,order_number', 'seller:id,name'])
            ->whereNotNull('intigo_attention')
            ->orderByDesc('updated_at')->limit(100)->get();

        $pending = SellerOrder::with(['order:id,order_number', 'seller:id,name'])
            ->where('intigo_sync_status', ParcelSender::PENDING)->whereNull('intigo_nid')
            ->orderBy('updated_at')->limit(100)->get();

        $logs = DB::table('intigo_logs')->orderByDesc('id')
            ->when($request->query('status') === 'errors', fn ($q) => $q->where(fn ($w) => $w->whereNull('http_status')->orWhere('http_status', '>=', 400)))
            ->limit(min(200, max(10, (int) $request->query('limit', 50))))
            ->get(['id', 'seller_order_id', 'mode', 'method', 'endpoint', 'http_status', 'duration_ms', 'error', 'created_at']);

        $events = DB::table('intigo_webhook_events')->orderByDesc('id')->limit(30)
            ->get(['id', 'nid', 'new_status', 'old_status', 'status_label', 'source', 'seller_order_id', 'result', 'processed_at', 'created_at']);

        $row = fn (SellerOrder $so) => [
            'seller_order_id'    => $so->id,
            'order_id'           => $so->order_id,
            'order_number'       => $so->order?->order_number,
            'seller'             => $so->seller?->name ?? "CHOOSE'Tounsi",
            'status'             => $so->status,
            'parcel_size'        => $so->parcel_size,
            'intigo_nid'         => $so->intigo_nid,
            'intigo_sync_status' => $so->intigo_sync_status,
            'intigo_error'       => $so->intigo_error,
            'intigo_attention'   => $so->intigo_attention,
            'intigo_status_label'=> $so->intigo_status_label,
            'attempts'           => $so->intigo_attempts,
            'updated_at'         => $so->updated_at,
        ];

        return response()->json(['success' => true, 'data' => [
            'mode'      => config('intigo.mode'),
            'setup'     => $this->setup(),
            'failed'    => $failed->map($row)->values(),
            'attention' => $attention->map($row)->values(),
            'pending'   => $pending->map($row)->values(),
            'logs'      => $logs,
            'events'    => $events,
        ]]);
    }

    public function log(int $id): JsonResponse
    {
        $log = DB::table('intigo_logs')->where('id', $id)->first();
        abort_unless($log, 404);
        $log->request  = json_decode((string) $log->request, true);
        $log->response = json_decode((string) $log->response, true);
        return response()->json(['success' => true, 'data' => $log]);
    }

    public function pickupAddresses(IntigoClient $client): JsonResponse
    {
        try {
            $addresses = $client->pickupAddresses();
        } catch (IntigoException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }
        $used = User::whereNotNull('intigo_pickup_index')->get(['id', 'name', 'intigo_pickup_index'])->groupBy('intigo_pickup_index');

        return response()->json(['success' => true, 'mode' => $client->mode(), 'data' => array_map(fn ($a) => [
            'index'   => $a['index'],
            'label'   => $a['label'],
            'used_by' => ($used[$a['index']] ?? collect())->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ], $addresses)]);
    }

    public function setSellerPickup(Request $request, int $sellerId): JsonResponse
    {
        $data = $request->validate([
            'index' => 'nullable|integer|min:0|max:100000',
            'label' => 'nullable|string|max:500',
        ]);
        $seller = User::findOrFail($sellerId);
        abort_unless(in_array($seller->role, ['seller', 'admin'], true) || $seller->sellerApplication, 422, 'Not a seller.');

        $seller->forceFill([
            'intigo_pickup_index' => $data['index'] ?? null,
            'intigo_pickup_label' => ($data['index'] ?? null) === null ? null : ($data['label'] ?? null),
        ])->save();
        Log::info("[Intigo] seller {$sellerId} pickup index → " . ($data['index'] ?? 'none') . ' by admin ' . $request->user()->id);

        return response()->json(['success' => true, 'message' => 'Intigo pickup address saved.', 'data' => [
            'intigo_pickup_index' => $seller->intigo_pickup_index,
            'intigo_pickup_label' => $seller->intigo_pickup_label,
        ]]);
    }

    public function parcel(int $id): JsonResponse
    {
        $so = SellerOrder::with(['order', 'seller:id,name,intigo_pickup_index,intigo_pickup_label'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => self::parcelPayload($so, true)]);
    }

    public function retry(Request $request, int $id, ParcelSender $sender): JsonResponse
    {
        $so = SellerOrder::findOrFail($id);
        if ($so->status !== 'confirmed') {
            return response()->json(['success' => false, 'message' => "Only a confirmed parcel can be sent (this one is {$so->status})."], 422);
        }
        Log::info("[Intigo] retry send of seller_order {$id} by admin {$request->user()->id}");
        $result = $sender->retry($id);

        $ok = in_array($result['result'], [ParcelSender::SENT, 'already_sent'], true);
        return response()->json([
            'success' => $ok || $result['result'] === 'retry',
            'result'  => $result['result'],
            'message' => $result['message'],
            'data'    => self::parcelPayload($so->fresh(['order', 'seller']), true),
        ], $ok || $result['result'] === 'retry' ? 200 : 422);
    }

    public function cancel(Request $request, int $id, ParcelCanceller $canceller): JsonResponse
    {
        $so = SellerOrder::findOrFail($id);
        if (!$so->intigo_nid) {
            return response()->json(['success' => false, 'message' => 'This parcel was not sent to Intigo.'], 422);
        }
        if (!IntigoStatusMap::isPickupPhase($so->intigo_status_code)) {
            return response()->json(['success' => false, 'message' => 'Past the pickup phase: cancel it in the Intigo dashboard.'], 422);
        }
        Log::info("[Intigo] cancel parcel {$so->intigo_nid} (seller_order {$id}) by admin {$request->user()->id}");
        $result = $canceller->cancel($id, 'Cancelled by an admin');

        $ok = in_array($result['result'], ['cancelled', 'already'], true);
        return response()->json([
            'success' => $ok,
            'result'  => $result['result'],
            'message' => $result['message'],
            'data'    => self::parcelPayload($so->fresh(['order', 'seller']), true),
        ], $ok ? 200 : 422);
    }

    public function dismiss(int $id): JsonResponse
    {
        SellerOrder::whereKey($id)->update(['intigo_attention' => null]);
        return response()->json(['success' => true, 'message' => 'Dismissed.']);
    }

    /**
     * Admin override of the parcel size before it is sent: package_size and
     * the Intigo cost for that size. What the client pays never changes (their
     * order total is frozen); the platform's delivery margin absorbs it.
     */
    public function setSize(Request $request, int $id, DeliverySettings $settings): JsonResponse
    {
        $data = $request->validate(['size' => 'required|in:' . implode(',', ParcelSize::ALL)]);

        $result = DB::transaction(function () use ($id, $data, $settings) {
            $so = SellerOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($so->intigo_nid || ($so->intigo_sync_status === ParcelSender::PENDING && $so->intigo_locked_at)) {
                return 'Already sent to Intigo: the size can no longer change.';
            }
            if (!in_array($so->status, ['pending', 'confirmed'], true)) {
                return "The parcel is {$so->status}: the size can no longer change.";
            }
            if ($so->getAttribute('settlement_batch_id')) {
                return 'The parcel is in a settlement batch.';
            }
            if ($so->parcel_size === $data['size']) {
                return null;
            }

            $m       = fn ($v) => Millimes::of($v ?? 0);
            $oldCost = $m($so->getAttribute('shipping_cost'));
            $cost    = $settings->agencyCost($data['size']);
            $isFree  = (bool) $so->getAttribute('is_free_delivery');
            $margin  = $isFree ? $m($so->getAttribute('seller_shipping_charge')) - $cost : $m($so->getAttribute('delivery_fee')) - $cost;
            $cod     = $m($so->getAttribute('cod_amount'));

            DB::table('seller_orders')->where('id', $so->id)->update([
                'parcel_size'              => $data['size'],
                'shipping_cost'            => Millimes::toDecimal($cost),
                'agency_delivery_cost'     => Millimes::toDecimal($cost),
                'platform_delivery_margin' => Millimes::toDecimal($margin),
                'platform_profit'          => Millimes::toDecimal($m($so->getAttribute('commission_amount')) + $margin),
                'amount_to_remit'          => Millimes::toDecimal($cod > 0 ? $cod - $cost : 0),
                'updated_at'               => now(),
            ]);
            // orders.shipping_cost = Σ agency costs of its parcels
            DB::table('orders')->where('id', $so->order_id)->update([
                'shipping_cost' => DB::raw('COALESCE(shipping_cost, 0) + ' . Millimes::toDecimal($cost - $oldCost)),
            ]);
            return null;
        });

        if ($result !== null) {
            return response()->json(['success' => false, 'message' => $result], 422);
        }
        \App\Services\FinancialSnapshotService::checkParcel($id);
        Log::info("[Intigo] seller_order {$id} parcel size → {$data['size']} by admin {$request->user()->id}");

        return response()->json(['success' => true, 'message' => 'Parcel size updated.', 'data' => self::parcelPayload(SellerOrder::with(['order', 'seller'])->findOrFail($id), true)]);
    }

    // ── Shared payload ─────────────────────────────────────────────────────

    /** The Intigo block of a parcel (admin order drawer). $full adds the history and logs. */
    public static function parcelPayload(SellerOrder $so, bool $full = false): array
    {
        $out = [
            'seller_order_id'          => $so->id,
            'parcel_size'              => $so->parcel_size,
            'parcel_size_computed'     => $so->parcel_size_computed,
            'package_size'             => ParcelSize::packageSize($so->parcel_size),
            'can_change_size'          => !$so->intigo_nid && in_array($so->status, ['pending', 'confirmed'], true),
            'nid'                      => $so->intigo_nid,
            'sync_status'              => $so->intigo_sync_status,
            'error'                    => $so->intigo_error,
            'attention'                => $so->intigo_attention,
            'status_code'              => $so->intigo_status_code,
            'status_label'             => $so->intigo_status_label,
            'status_at'                => $so->intigo_status_at,
            'sent_at'                  => $so->intigo_sent_at,
            'district_fallback'        => $so->intigo_district_fallback,
            'district_name'            => $so->intigo_district_name,
            'attempts'                 => $so->intigo_attempts,
            'previous_nids'            => $so->intigo_previous_nids ?? [],
            'pickup_index'             => ParcelPayload::pickupIndex($so),
            'cod_amount'               => Millimes::toFloat(ParcelPayload::codMillimes($so)),
            'can_retry'                => $so->status === 'confirmed'
                && (!$so->intigo_nid || $so->intigo_sync_status === ParcelSender::CANCELLED || IntigoStatusMap::isCancelled($so->intigo_status_code))
                && $so->intigo_sync_status !== ParcelSender::SENT,
            'can_cancel'               => (bool) $so->intigo_nid
                && $so->intigo_sync_status !== ParcelSender::CANCELLED
                && IntigoStatusMap::isPickupPhase($so->intigo_status_code),
            'mode'                     => config('intigo.mode'),
        ];
        if ($full) {
            $nids = array_values(array_filter([...($so->intigo_previous_nids ?? []), $so->intigo_nid]));
            $out['history'] = DB::table('intigo_webhook_events')
                ->where(fn ($q) => $q->where('seller_order_id', $so->id)->orWhereIn('nid', $nids ?: ['-']))
                ->orderBy('id')->get(['id', 'nid', 'new_status', 'old_status', 'status_label', 'source', 'result', 'event_timestamp', 'created_at']);
            $out['logs'] = DB::table('intigo_logs')->where('seller_order_id', $so->id)->orderByDesc('id')->limit(30)
                ->get(['id', 'mode', 'method', 'endpoint', 'http_status', 'duration_ms', 'error', 'created_at']);
            $out['payload_problems'] = $so->intigo_nid ? [] : app(ParcelPayload::class)->build($so)['problems'];
        }
        return $out;
    }

    private function setup(): array
    {
        $sellersWithIndex = User::whereNotNull('intigo_pickup_index')->count();
        $activeSellers    = User::where('role', 'seller')->where('is_active', true)->count();
        return [
            'api_key_set'           => filled(config('intigo.api_key')),
            'webhook_secret_set'    => filled(config('intigo.webhook_secret')),
            'webhook_url'           => rtrim(config('app.url'), '/') . '/api/webhooks/intigo',
            'regions_synced'        => IntigoRegions::available(),
            'cities'                => DB::table('intigo_cities')->count(),
            'districts'             => DB::table('intigo_districts')->count(),
            'platform_pickup_index' => config('intigo.platform_pickup_index'),
            'sellers_with_pickup'   => $sellersWithIndex,
            'active_sellers'        => $activeSellers,
            'can_open'              => app(DeliverySettings::class)->canOpen(),
        ];
    }
}
