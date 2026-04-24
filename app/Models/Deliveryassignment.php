<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_order_id',
        'delivery_guy_id',
        'assigned_by',
        'status',
        'assigned_at',
        'picked_up_at',
        'delivered_at',
        'notes',
    ];

    protected $casts = [
        'assigned_at'  => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function deliveryGuy()
    {
        return $this->belongsTo(User::class, 'delivery_guy_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeAssigned($q)   { return $q->where('status', 'assigned'); }
    public function scopePickedUp($q)   { return $q->where('status', 'picked_up'); }
    public function scopeDelivered($q)  { return $q->where('status', 'delivered'); }
    public function scopeCanceled($q)   { return $q->where('status', 'canceled'); }
}