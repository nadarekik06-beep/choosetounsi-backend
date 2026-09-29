<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One impression, click or conversion of a campaign (append-only). */
class SponsorshipEvent extends Model
{
    public const UPDATED_AT = null;

    public const IMPRESSION          = 'impression';
    public const CLICK               = 'click';
    public const CONVERSION          = 'conversion';
    public const CONVERSION_REVERSED = 'conversion_reversed';

    protected $fillable = [
        'sponsorship_id', 'event', 'placement', 'user_id', 'session_id', 'request_id', 'cost',
        'credit_cost', 'billable', 'countable', 'order_id', 'revenue', 'click_event_id', 'ip_hash', 'created_at',
    ];

    protected $casts = [
        'cost'        => 'decimal:3',
        'credit_cost' => 'decimal:3',
        'revenue'     => 'decimal:3',
        'billable'    => 'boolean',
        'countable'   => 'boolean',
    ];

    public function sponsorship()
    {
        return $this->belongsTo(Sponsorship::class);
    }
}
