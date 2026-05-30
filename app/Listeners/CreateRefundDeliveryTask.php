<?php

namespace App\Listeners;

use App\Events\ComplaintApproved;
use App\Models\Complaint;
use App\Models\RefundDeliveryTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Listener: CreateRefundDeliveryTask
 *
 * Triggered by: ComplaintApproved event
 *
 * Creates a lean RefundDeliveryTask with only the operational columns.
 * All context (customer info, seller info, items, complaint details) is
 * resolved at query time via relations — nothing is snapshotted here.
 *
 * Relations used by RefundDeliveryController::formatTask():
 *   $task->complaint->order->user      → customer name, phone, wilaya, address
 *   $task->complaint->order->items     → items list
 *   $task->complaint                   → type, description, image_url
 *   $task->seller->sellerApplication   → seller phone, wilaya, city, business_name
 */
class CreateRefundDeliveryTask
{
    public function handle(ComplaintApproved $event): void
    {
        $complaint = $event->complaint;

        // Guard: skip if task already exists (idempotent)
        if ($complaint->hasRefundTask()) {
            Log::info("[RefundTask] Complaint #{$complaint->id} already has a refund task — skipping.");
            return;
        }

        // Guard: only create for approved complaints
        if (!$complaint->isApproved()) {
            Log::warning("[RefundTask] ComplaintApproved event fired for non-approved complaint #{$complaint->id} — skipping.");
            return;
        }

        try {
            DB::transaction(function () use ($complaint) {

                $task = RefundDeliveryTask::create([
                    'complaint_id' => $complaint->id,
                    'seller_id'    => $complaint->seller_id,
                    'status'       => RefundDeliveryTask::STATUS_PENDING,
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