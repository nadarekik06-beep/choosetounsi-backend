<?php
// app/Http/Controllers/Admin/SettlementController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Support\SettlementProof;
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
    ->leftJoin('users as pu', 'pu.id', '=', 'sb.proof_uploaded_by')
    ->select([
        'sb.*',
        'pu.name as proof_uploaded_by_name',
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
        // the storage path stays server-side; rows get has_proof
        $results->setCollection($results->getCollection()->map(fn ($r) => SettlementProof::present($r, true)));

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
        ] + SettlementProof::rules());

        $sellerId  = (int) $request->seller_id;
        $batchDate = Carbon::parse($request->batch_date)->toDateString();

        // CHOOSE'Tounsi's own products: their net is platform revenue, never a payout
        if (\App\Helpers\PlatformUser::isPlatform($sellerId)) {
            return response()->json([
                'success' => false,
                'message' => "CHOOSE'Tounsi's own parcels are platform revenue: they are never settled as a seller payout.",
            ], 422);
        }

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

        $proof = $request->hasFile('proof') ? SettlementProof::store($request->file('proof'), (int) auth()->id()) : [];

        DB::beginTransaction();
        try {
            // Seller debits not settled yet (returns refunded after a payout,
            // return shipping): deducted from this payout, history untouched.
            $adjustments = DB::table('seller_adjustments')
                ->where('seller_id', $sellerId)
                ->whereNull('settlement_batch_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            // A payout never goes below 0: debits it cannot cover stay pending (the
            // seller's debt) and come off the next payouts
            $adjustments = $this->carryOverDebt($adjustments, (float) $orders->sum('seller_net_amount'));
            $totalAdjustments = round((float) $adjustments->sum('amount'), 3);

            // Compute batch totals
            $grossRevenue       = $orders->sum(fn($o) => (float) $o->subtotal - (float) $o->discount_amount);
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
                'total_seller_payout'  => round($totalSellerPayout + $totalAdjustments, 3),
                'total_adjustments'    => $totalAdjustments,
                'total_platform_profit'=> round($totalPlatformProfit, 3),
                'orders_count'         => $orders->count(),
                'status'               => 'draft',
                'created_by'           => auth()->id(),
                'notes'                => $request->notes,
                'created_at'           => now(),
                'updated_at'           => now(),
            ] + SettlementProof::paymentFields($request) + $proof);

            // Link orders to batch
            DB::table('seller_orders')
                ->whereIn('id', $orders->pluck('id')->toArray())
                ->update([
                    'settlement_batch_id' => $batchId,
                    'updated_at'          => now(),
                ]);

            if ($adjustments->isNotEmpty()) {
                DB::table('seller_adjustments')->whereIn('id', $adjustments->pluck('id'))
                    ->update(['settlement_batch_id' => $batchId, 'updated_at' => now()]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Batch {$ref} created with {$orders->count()} order(s).",
                'data'    => SettlementProof::present(DB::table('settlement_batches')->where('id', $batchId)->first(), true),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            SettlementProof::delete($proof['proof_path'] ?? null);
            Log::error('[SettlementController::create] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create settlement batch.',
            ], 500);
        }
    }

    /**
     * Debits (negative adjustments) larger than this payout: the newest ones stay
     * pending for the next batch. One debit may be split in two rows (the part
     * settled now, the part carried over); their sum never changes.
     *
     * @param  \Illuminate\Support\Collection $adjustments pending rows, oldest first (locked)
     * @return \Illuminate\Support\Collection the rows this batch settles
     */
    private function carryOverDebt($adjustments, float $payout)
    {
        $m        = fn ($v) => \App\Support\Millimes::of($v ?? 0);
        $deficitM = -($m(number_format($payout, 3, '.', '')) + $adjustments->sum(fn ($a) => $m($a->amount)));
        if ($deficitM <= 0) {
            return $adjustments;
        }

        $keep = $adjustments->keyBy('id');
        foreach ($adjustments->sortByDesc('id') as $a) {
            $owedM = -$m($a->amount);
            if ($deficitM <= 0 || $owedM <= 0) continue;

            if ($owedM <= $deficitM) {
                $keep->forget($a->id);                      // whole debit waits for the next payout
                $deficitM -= $owedM;
                continue;
            }
            // Part of it is settled now, the rest is carried over as its own pending row
            DB::table('seller_adjustments')->where('id', $a->id)
                ->update(['amount' => \App\Support\Millimes::toDecimal(-($owedM - $deficitM)), 'updated_at' => now()]);
            DB::table('seller_adjustments')->insert([
                'seller_id'       => $a->seller_id,
                'seller_order_id' => $a->seller_order_id,
                'complaint_id'    => $a->complaint_id,
                'type'            => $a->type,
                'amount'          => \App\Support\Millimes::toDecimal(-$deficitM),
                'description'     => \Illuminate\Support\Str::limit((string) $a->description, 200, '') . ' (carried over)',
                'created_by'      => $a->created_by,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            $a->amount = \App\Support\Millimes::toDecimal(-($owedM - $deficitM));
            $deficitM  = 0;
        }
        return $keep->values();
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
                DB::raw('(so.subtotal - so.discount_amount) as subtotal'),
                'so.discount_amount',
                'so.commission_amount',
                'so.seller_net_amount',
                'so.delivery_fee',
                'so.seller_shipping_charge',
                'so.status',
                'so.money_received_at',
                'so.created_at',
            ])
            ->get();

        $adjustments = DB::table('seller_adjustments as a')
            ->leftJoin('complaints as c', 'c.id', '=', 'a.complaint_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.created_by')
            ->where('a.settlement_batch_id', $id)
            ->orderBy('a.id')
            ->get(['a.id', 'a.type', 'a.amount', 'a.description', 'a.created_at', 'a.applied_at', 'c.reference as return_reference', 'u.name as created_by_name']);

        return response()->json([
            'success' => true,
            'data'    => array_merge((array) SettlementProof::present($batch, true), [
                'orders'      => $orders,
                'adjustments' => $adjustments,
                'proof_uploaded_by_name' => $batch->proof_uploaded_by
                    ? DB::table('users')->where('id', $batch->proof_uploaded_by)->value('name') : null,
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/settlements/{id}/confirm
    // ─────────────────────────────────────────────────────────────────────────

    public function confirm(Request $request, int $id): JsonResponse
    {
        $request->validate(SettlementProof::rules());

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

        $proof = $request->hasFile('proof') ? SettlementProof::store($request->file('proof'), (int) auth()->id()) : [];

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
            ] + SettlementProof::paymentFields($request) + $proof);

            // Mark all orders in batch as paid (a refunded return keeps its payment_status)
            DB::table('seller_orders')
                ->where('settlement_batch_id', $id)
                ->update([
                    'payout_status'  => 'paid',
                    'payment_status' => DB::raw("CASE WHEN payment_status = 'refunded' THEN 'refunded' ELSE 'paid' END"),
                    'settled_at'     => $now,
                    'updated_at'     => $now,
                ]);

            // Debits carried by this batch are now settled
            DB::table('seller_adjustments')->where('settlement_batch_id', $id)
                ->update(['applied_at' => $now, 'updated_at' => $now]);

            DB::commit();

            // A receipt exists (uploaded now, or attached while the batch was a draft)
            if ($proof || $batch->proof_path) {
                $this->notifySeller($id);
            }

            return response()->json([
                'success' => true,
                'message' => "Batch {$batch->batch_reference} confirmed. Seller marked as paid.",
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            SettlementProof::delete($proof['proof_path'] ?? null);
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

        DB::transaction(function () use ($id) {
            $now = now();

            DB::table('settlement_batches')->where('id', $id)->update([
                'status'     => 'cancelled',
                'updated_at' => $now,
            ] + SettlementProof::emptyColumns());

            // Orders and seller debits go back to the queue for the next batch
            DB::table('seller_orders')->where('settlement_batch_id', $id)
                ->update(['settlement_batch_id' => null, 'updated_at' => $now]);
            DB::table('seller_adjustments')->where('settlement_batch_id', $id)
                ->update(['settlement_batch_id' => null, 'updated_at' => $now]);
        });

        SettlementProof::delete($batch->proof_path);

        return response()->json([
            'success' => true,
            'message' => "Batch {$batch->batch_reference} cancelled. Its orders are ready for a new batch.",
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Proof of payment: GET | POST | DELETE /api/admin/settlements/{id}/payment-proof
    // ─────────────────────────────────────────────────────────────────────────

    public function showProof(int $id)
    {
        $batch = DB::table('settlement_batches')->where('id', $id)->first();
        abort_unless($batch, 404, 'Batch not found.');

        return SettlementProof::response($batch);
    }

    /** Adds or replaces the proof and/or edits the payment details (method, reference, date). */
    public function storeProof(Request $request, int $id): JsonResponse
    {
        $request->validate(SettlementProof::rules());

        $batch = DB::table('settlement_batches')->where('id', $id)->first();
        if (!$batch) {
            return response()->json(['success' => false, 'message' => 'Batch not found.'], 404);
        }
        if ($batch->status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'A cancelled batch cannot take a payment proof.'], 422);
        }

        $fields = SettlementProof::paymentFields($request);
        if (!$request->hasFile('proof') && !$fields) {
            return response()->json(['success' => false, 'message' => 'Nothing to save: send a proof file or payment details.'], 422);
        }

        $proof = $request->hasFile('proof') ? SettlementProof::store($request->file('proof'), (int) auth()->id()) : [];

        try {
            DB::table('settlement_batches')->where('id', $id)->update($fields + $proof + ['updated_at' => now()]);
        } catch (\Throwable $e) {
            SettlementProof::delete($proof['proof_path'] ?? null);
            Log::error('[SettlementController::storeProof] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Could not save the proof.'], 500);
        }

        if ($proof) {
            SettlementProof::delete($batch->proof_path);          // the replaced file
            if ($batch->status === 'paid') {
                $this->notifySeller($id);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment proof saved.',
            'data'    => SettlementProof::present(DB::table('settlement_batches')->where('id', $id)->first(), true),
        ]);
    }

    public function destroyProof(int $id): JsonResponse
    {
        $batch = DB::table('settlement_batches')->where('id', $id)->first();
        if (!$batch) {
            return response()->json(['success' => false, 'message' => 'Batch not found.'], 404);
        }
        if (!$batch->proof_path) {
            return response()->json(['success' => false, 'message' => 'This batch has no proof.'], 404);
        }

        DB::table('settlement_batches')->where('id', $id)
            ->update(SettlementProof::emptyColumns() + ['updated_at' => now()]);
        SettlementProof::delete($batch->proof_path);
        Log::info('[SettlementController] proof removed', ['batch' => $id, 'admin' => auth()->id()]);

        return response()->json(['success' => true, 'message' => 'Payment proof removed.']);
    }

    /** In-app + e-mail notice to the seller; a failure here never undoes the payment. */
    private function notifySeller(int $batchId): void
    {
        try {
            $batch  = DB::table('settlement_batches')->where('id', $batchId)->first();
            $seller = \App\Models\User::find($batch->seller_id);
            if ($seller) {
                $seller->notify(new \App\Notifications\Sellers\SettlementPaidNotification(
                    $batchId,
                    $batch->batch_reference,
                    \App\Services\Payments\Money::plain($batch->total_seller_payout),
                ));
            }
        } catch (\Throwable $e) {
            Log::error('[SettlementController::notifySeller] ' . $e->getMessage());
        }
    }
}