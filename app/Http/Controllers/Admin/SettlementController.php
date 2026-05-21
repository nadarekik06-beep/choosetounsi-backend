<?php
// app/Http/Controllers/Admin/SettlementController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SettlementController — daily batch settlement system.
 *
 * Routes:
 *   GET  /api/admin/settlements            — list all batches
 *   POST /api/admin/settlements/create     — create a new batch for a seller + date
 *   GET  /api/admin/settlements/{id}       — batch detail
 *   POST /api/admin/settlements/{id}/confirm — confirm batch (mark seller as paid)
 *   POST /api/admin/settlements/{id}/cancel  — cancel a draft batch
 */
class SettlementController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/settlements
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('settlement_batches as sb')
    ->join('users as u', 'u.id', '=', 'sb.seller_id')
    ->leftJoin('seller_applications as sa', function ($join) {
        $join->on('sa.user_id', '=', 'sb.seller_id')
             ->where('sa.status', '=', 'approved');
    })
    ->select([
        'sb.*',
        'u.name as seller_name',
        'u.email as seller_email',
        'sa.phone_number as seller_phone',
    ]);

        if ($s = $request->query('status')) {
            $query->where('sb.status', $s);
        }
        if ($s = $request->query('seller_id')) {
            $query->where('sb.seller_id', $s);
        }
        if ($d = $request->query('date_from')) {
            $query->where('sb.batch_date', '>=', $d);
        }
        if ($d = $request->query('date_to')) {
            $query->where('sb.batch_date', '<=', $d);
        }
        if ($s = $request->query('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('u.name',           'like', "%$s%")
                  ->orWhere('sa.phone_number', 'like', "%$s%");
            });
        }

$results = $query->orderByDesc('sb.batch_date')->paginate(15);

        return response()->json(['success' => true, 'data' => $results]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/settlements/create
    // ─────────────────────────────────────────────────────────────────────────

    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'seller_id'  => 'required|integer|exists:users,id',
            'batch_date' => 'required|date',
            'notes'      => 'nullable|string|max:500',
        ]);

        $sellerId  = (int) $request->seller_id;
        $batchDate = Carbon::parse($request->batch_date)->toDateString();

        // Find all ready orders for this seller (not yet in a batch)
        $orders = DB::table('seller_orders')
            ->where('seller_id', $sellerId)
            ->where('payout_status', 'ready')
            ->whereNull('settlement_batch_id')
            ->whereNotNull('money_received_at')
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No orders ready for settlement for this seller.',
            ], 422);
        }

        // Duplicate batch guard
        $existing = DB::table('settlement_batches')
            ->where('seller_id', $sellerId)
            ->where('batch_date', $batchDate)
            ->whereIn('status', ['draft', 'confirmed', 'paid'])
            ->exists();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'A settlement batch for this seller and date already exists.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Compute batch totals
            $grossRevenue       = $orders->sum('subtotal');
            $totalCommission    = $orders->sum('commission_amount');
            $totalDeliveryFees  = $orders->sum('delivery_fee');
            $totalSellerPayout  = $orders->sum('seller_net_amount');
            $totalPlatformProfit= $orders->sum('platform_profit');

            // Generate unique batch reference
            $ref = 'BATCH-' . $batchDate . '-' . str_pad(
                DB::table('settlement_batches')
                    ->whereDate('created_at', today())
                    ->count() + 1,
                3, '0', STR_PAD_LEFT
            );

            // Create batch
            $batchId = DB::table('settlement_batches')->insertGetId([
                'batch_reference'      => $ref,
                'seller_id'            => $sellerId,
                'batch_date'           => $batchDate,
                'total_orders_gross'   => round($grossRevenue,        3),
                'total_commission'     => round($totalCommission,     3),
                'total_delivery_fees'  => round($totalDeliveryFees,   3),
                'total_seller_payout'  => round($totalSellerPayout,   3),
                'total_platform_profit'=> round($totalPlatformProfit, 3),
                'orders_count'         => $orders->count(),
                'status'               => 'draft',
                'created_by'           => auth()->id(),
                'notes'                => $request->notes,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // Link orders to batch
            DB::table('seller_orders')
                ->whereIn('id', $orders->pluck('id')->toArray())
                ->update([
                    'settlement_batch_id' => $batchId,
                    'updated_at'          => now(),
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Batch {$ref} created with {$orders->count()} order(s).",
                'data'    => DB::table('settlement_batches')->where('id', $batchId)->first(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SettlementController::create] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create settlement batch.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/settlements/{id}
    // ─────────────────────────────────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $batch = DB::table('settlement_batches as sb')
            ->join('users as u', 'u.id', '=', 'sb.seller_id')
            ->where('sb.id', $id)
            ->select(['sb.*', 'u.name as seller_name', 'u.email as seller_email'])
            ->first();

        if (!$batch) {
            return response()->json(['success' => false, 'message' => 'Batch not found.'], 404);
        }

        // Orders in this batch
        $orders = DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->where('so.settlement_batch_id', $id)
            ->select([
                'so.id',
                'o.order_number',
                'so.subtotal',
                'so.commission_amount',
                'so.seller_net_amount',
                'so.delivery_fee',
                'so.status',
                'so.money_received_at',
                'so.created_at',
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => array_merge((array) $batch, ['orders' => $orders]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/settlements/{id}/confirm
    // ─────────────────────────────────────────────────────────────────────────

    public function confirm(int $id): JsonResponse
    {
        $batch = DB::table('settlement_batches')->where('id', $id)->first();

        if (!$batch) {
            return response()->json(['success' => false, 'message' => 'Batch not found.'], 404);
        }

        if ($batch->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => "Batch is already {$batch->status}.",
            ], 422);
        }

        DB::beginTransaction();
        try {
            $now = now();

            // Mark batch as paid
            DB::table('settlement_batches')->where('id', $id)->update([
                'status'       => 'paid',
                'confirmed_by' => auth()->id(),
                'confirmed_at' => $now,
                'paid_at'      => $now,
                'updated_at'   => $now,
            ]);

            // Mark all orders in batch as paid
            DB::table('seller_orders')
                ->where('settlement_batch_id', $id)
                ->update([
                    'payout_status' => 'paid',
                    'settled_at'    => $now,
                    'updated_at'    => $now,
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Batch {$batch->batch_reference} confirmed. Seller marked as paid.",
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SettlementController::confirm] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Confirmation failed.'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/settlements/{id}/cancel
    // ─────────────────────────────────────────────────────────────────────────

    public function cancel(int $id): JsonResponse
    {
        $batch = DB::table('settlement_batches')->where('id', $id)->first();

        if (!$batch || $batch->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft batches can be cancelled.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Unlink orders — put them back to 'ready'
            DB::table('seller_orders')
                ->where('settlement_batch_id', $id)
                ->update([
                    'settlement_batch_id' => null,
                    'updated_at'          => now(),
                ]);

            DB::table('settlement_batches')->where('id', $id)->update([
                'status'     => 'cancelled',
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Batch cancelled. Orders returned to ready queue.',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Cancellation failed.'], 500);
        }
    }
}