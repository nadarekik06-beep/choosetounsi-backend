<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One WhatsApp message the admin sends a seller about a confirmed parcel:
 * attempt 0 = initial notice, 1 and 2 = reminders. Written only by
 * App\Services\Orders\WhatsApp\SellerReminderService.
 *
 *   pending ─(due_at passed)─▶ due ─(admin sent it)─▶ sent
 *      └──────────┴──(prepared, cancelled, shipped…)──▶ cancelled
 *
 * @property int    $id
 * @property int    $seller_order_id
 * @property int    $attempt
 * @property string $status
 */
class SellerOrderReminder extends Model
{
    public const PENDING   = 'pending';
    public const DUE       = 'due';
    public const SENT      = 'sent';
    public const CANCELLED = 'cancelled';

    /** Still waiting to be sent. */
    public const OPEN = [self::PENDING, self::DUE];

    protected $guarded = ['id'];

    protected $casts = [
        'attempt' => 'integer',
        'due_at'  => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by_admin_id');
    }
}
