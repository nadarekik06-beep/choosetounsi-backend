<?php

namespace App\Notifications;

use App\Models\Complaint;
use App\Notifications\Buyer\ComplaintNotification;

/**
 * The buyer's complaint was decided: approved or rejected (admin, or the shop's approval).
 * Send through App\Services\Notifications\BuyerNotifier.
 */
class ComplaintStatusChangedNotification extends ComplaintNotification
{
    public function __construct(Complaint $complaint)
    {
        parent::__construct($complaint, $complaint->isApproved() ? 'approved' : 'rejected');
    }
}
