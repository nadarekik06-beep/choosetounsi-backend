<?php

namespace App\Events;

use App\Models\Complaint;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: ComplaintApproved
 *
 * Fired whenever a complaint reaches the APPROVED status, regardless of
 * which actor triggered it:
 *   - Seller directly approves  (Complaint::sellerApprove)
 *   - Admin approves            (Complaint::approve)
 *   - Admin overrides rejection (Complaint::overrideToApproved)
 *
 * The CreateRefundDeliveryTask listener subscribes to this event and
 * automatically creates a RefundDeliveryTask row.
 *
 * WHY AN EVENT AND NOT A DIRECT CALL?
 *   Using an event keeps the Complaint model decoupled from the refund
 *   delivery system. If you later want to add more actions on approval
 *   (e.g. send SMS, update analytics), you just add another listener —
 *   zero changes to the model or controllers.
 */
class ComplaintApproved
{
    use Dispatchable, SerializesModels;

    /**
     * The approved complaint.
     * Passed as a fresh() instance from the model so all columns are up to date.
     */
    public Complaint $complaint;

    public function __construct(Complaint $complaint)
    {
        $this->complaint = $complaint;
    }
}