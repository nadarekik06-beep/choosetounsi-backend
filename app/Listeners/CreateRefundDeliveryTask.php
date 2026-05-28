<?php

namespace App\Listeners;

use App\Events\ComplaintApproved;
use App\Models\Complaint;
use App\Models\RefundDeliveryTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Listener: CreateRefundDeliveryTask
 *
 * Triggered by: ComplaintApproved event
 *
 * customer_name/phone/wilaya/address are NOT stored anymore — resolved via
 * complaint → order → user at query time.
 *
 * seller_name/business_name are NOT stored anymore — resolved via
 * seller_id → users → seller_applications at query time.
 *
 * Still stored as snapshots (delivery guy needs them offline):
 *   seller_phone, seller_wilaya, seller_city
 *   items_summary, complaint_type, complaint_description, complaint_image_url
 */
class CreateRefundDeliveryTask
{
    public function handle(ComplaintApproved $event): void
    {
        $complaint = $event->complaint;

        if ($complaint->hasRefundTask()) {
            Log::info("[RefundTask] Complaint #{$complaint->id} already has a refund task — skipping.");
            return;
        }

        if (!$complaint->isApproved()) {
            Log::warning("[RefundTask] ComplaintApproved event fired for non-approved complaint #{$complaint->id} — skipping.");
            return;
        }

        try {
            DB::transaction(function () use ($complaint) {
                $order = $complaint->order()->with('user')->first();

                if (!$order) {
                    Log::error("[RefundTask] Complaint #{$complaint->id}: order not found.");
                    return;
                }

                $seller      = User::with('sellerApplication')->find($complaint->seller_id);
                $application = $seller?->sellerApplication;

                $items = DB::table('order_items')
                    ->where('order_id', $order->id)
                    ->select('product_name', 'quantity')
                    ->get()
                    ->map(fn($i) => [
                        'product_name' => $i->product_name,
                        'quantity'     => (int) $i->quantity,
                    ])
                    ->toArray();

                $task = RefundDeliveryTask::create([
                    'complaint_id'          => $complaint->id,
                    'order_id'              => $order->id,
                    'seller_id'             => $complaint->seller_id,

                    // ── Seller contact snapshot (kept — delivery guy needs these) ──
                    'seller_phone'          => $application?->phone_number,
                    'seller_wilaya'         => $application?->wilaya,
                    'seller_city'           => $application?->city,

                    // ── Items snapshot ─────────────────────────────────────────────
                    'items_summary'         => $items,

                    // ── Complaint context snapshot ─────────────────────────────────
                    'complaint_type'        => $complaint->complaint_type,
                    'complaint_description' => $complaint->description,
                    'complaint_image_url'   => $complaint->image_url,

                    'status'                => RefundDeliveryTask::STATUS_PENDING,
                ]);

                $complaint->update([
                    'refund_status'  => Complaint::REFUND_STATUS_PENDING,
                    'refund_task_id' => $task->id,
                ]);

                Log::info("[RefundTask] Created task #{$task->id} for complaint #{$complaint->id}.");
            });

        } catch (\Throwable $e) {
            Log::error("[RefundTask] Failed to create refund task for complaint #{$complaint->id}: " . $e->getMessage());
        }
    }
}