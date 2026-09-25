<?php

// Generated from one shared table (fr / ar / en) — keep the three files in sync.

return [
    'complaint_approved' => [
        'title' => 'Complaint Approved ✅',
        'body' => 'Your complaint has been approved. We will contact you about next steps.',
        'message' => 'Your complaint has been approved. Please check your email for next steps.',
        'subject' => '✅ Your Complaint Has Been Approved — Order :order',
        'line1' => 'Great news! Your complaint for order **:order** has been **approved**.',
        'line2' => 'Our team will contact you shortly regarding the next steps (refund or replacement).',
        'action' => 'View My Complaints',
    ],
    'complaint_rejected' => [
        'title' => 'Complaint Rejected ❌',
        'body' => 'Your complaint was not approved. Reason: :reason',
        'subject' => '❌ Update on Your Complaint — Order :order',
        'line1' => 'We have reviewed your complaint for order **:order**.',
        'line2' => 'Unfortunately, after careful review, your complaint could not be approved.',
        'reason' => '**Reason:** :reason',
        'line3' => 'If you believe this is incorrect, please contact our support team.',
        'action' => 'Contact Support',
    ],
    'refund_completed' => [
        'title' => '✅ Your refund has been processed',
        'message' => 'The refund for order #:order has been completed. Your order status has been updated to Refunded.',
        'subject' => '✅ Refund Completed — Order #:order',
        'line1' => 'We are pleased to inform you that the refund for your order **#:order** has been successfully processed.',
        'line2' => 'Our delivery agent has picked up the item and the return has been confirmed.',
        'line3' => 'Your order status has been updated to **Refunded**.',
        'action' => 'View My Orders',
        'thanks' => 'Thank you for shopping with Choose\'Tounsi. We apologize for any inconvenience caused.',
    ],
    'review_prompt' => [
        'title' => 'How was your order?',
        'message' => 'Share your experience with :products',
        'product' => 'Product',
    ],
    'mail' => [
        'greeting' => 'Hello :name,',
        'thanks' => 'Thank you for shopping with ChooseTounsi.',
    ],
];
