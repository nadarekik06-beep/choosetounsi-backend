<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

// ── Refund Delivery events (NEW) ───────────────────────────────────────────────
use App\Events\ComplaintApproved;
use App\Events\RefundCompleted;
use App\Listeners\CreateRefundDeliveryTask;
use App\Listeners\MarkOrderRefunded;

/**
 * FILE: app/Providers/EventServiceProvider.php  ← REPLACE existing file
 *
 * Changes:
 *   - Added ComplaintApproved → CreateRefundDeliveryTask
 *   - Added RefundCompleted   → MarkOrderRefunded
 *
 * All existing bindings are preserved.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // ── Framework ─────────────────────────────────────────────────────
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // ── Refund Delivery Flow (NEW) ─────────────────────────────────────

        /**
         * ComplaintApproved fires when a complaint reaches APPROVED status.
         * Triggered by 3 paths:
         *   - SellerComplaintController::approve (seller direct approval)
         *   - AdminComplaintController::approve  (admin direct approval)
         *   - AdminComplaintController::overrideToApproved (admin overrides seller rejection)
         *
         * The CreateRefundDeliveryTask listener creates a RefundDeliveryTask row
         * and sets complaint.refund_status = 'pending'.
         */
        ComplaintApproved::class => [
            CreateRefundDeliveryTask::class,
        ],

        /**
         * RefundCompleted fires when a delivery guy marks a refund task as
         * 'completed' via PUT /api/delivery/refunds/{id}/status.
         *
         * The MarkOrderRefunded listener cascades the status update to:
         *   - orders.status           → 'refunded'
         *   - seller_orders.status    → 'refunded'
         *   - complaints.refund_status → 'completed'
         */
        RefundCompleted::class => [
            MarkOrderRefunded::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }
}