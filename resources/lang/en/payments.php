<?php

// Manual (WhatsApp) payments: ad-wallet top-ups and plan changes.
return [
    'errors' => [
        'method_disabled'        => 'Payment via WhatsApp is turned off for now.',
        'not_seller'             => 'Only an approved seller can make this request.',
        'amount_out_of_range'    => 'The amount must be between :min and :max.',
        'invalid_amount'         => 'Invalid amount.',
        'too_many_pending'       => 'You already have :count pending top-up request(s). Wait until they are handled or cancel one.',
        'upgrade_pending'        => 'You already have a pending plan request (:reference). Cancel it to create another one.',
        'plan_unavailable'       => 'This plan is not available.',
        'no_yearly_price'        => 'The :plan plan has no yearly price.',
        'not_an_upgrade'         => 'The :plan plan is not above your current plan.',
        'subscription_suspended' => 'Your subscription is suspended. Please contact support.',
        'already_decided'        => 'This request has already been handled (:status).',
        'not_found'              => 'Request not found.',
        'use_payment_request'    => 'Card payment is not available. Send a plan change request instead (payment via WhatsApp).',
    ],
    'status' => [
        'pending'   => 'pending',
        'approved'  => 'approved',
        'rejected'  => 'rejected',
        'cancelled' => 'cancelled',
    ],
    'notif' => [
        'wallet_topup' => [
            'approved' => ['title' => 'Top-up :reference confirmed', 'body' => ':amount DT were added to your ad wallet. Campaigns paused for lack of funds resume automatically.'],
            'rejected' => ['title' => 'Top-up :reference rejected', 'body' => 'Your top-up request was rejected. Reason: :reason'],
        ],
        'plan_upgrade' => [
            'approved' => ['title' => 'Your :plan plan is active', 'body' => 'Payment :reference received (:amount DT). Your :plan plan is active until :end.'],
            'rejected' => ['title' => 'Request :reference rejected', 'body' => 'Your request to move to the :plan plan was rejected. Reason: :reason'],
        ],
        'view' => 'View details',
    ],
];
