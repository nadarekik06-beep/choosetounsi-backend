<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Order line → the ad click it came from. Whether it still counts is read live from
 * the order (AdMetrics); `status` is only a snapshot refreshed by ads:rebuild-stats
 * (pending → converted when delivered, reversed when cancelled/refunded).
 */
class OrderAdAttribution extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_REVERSED  = 'reversed';

    protected $fillable = [
        'order_id', 'order_item_id', 'sponsorship_id', 'click_event_id', 'revenue', 'status',
        'converted_at', 'reversed_at',
    ];

    protected $casts = [
        'revenue'      => 'decimal:3',
        'converted_at' => 'datetime',
        'reversed_at'  => 'datetime',
    ];

    public function sponsorship()
    {
        return $this->belongsTo(Sponsorship::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
