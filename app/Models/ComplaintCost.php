<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One after-sale parcel in the books: what Intigo bills us for it and what we
 * got back (client refund deduction or cash on the exchange parcel; seller
 * settlement deduction). margin = recovered − intigo_cost.
 *
 *   return           a complaint return (Intigo bills it as a normal parcel of that size)
 *   exchange         an exchange parcel delivered
 *   exchange_failed  an exchange refused at the door (Intigo's refused-parcel fee)
 */
class ComplaintCost extends Model
{
    public const KIND_RETURN          = 'return';
    public const KIND_EXCHANGE        = 'exchange';
    public const KIND_EXCHANGE_FAILED = 'exchange_failed';

    protected $fillable = [
        'complaint_id', 'kind', 'size', 'intigo_cost', 'recovered_client', 'recovered_seller', 'payer', 'intigo_nid', 'booked_at',
    ];

    protected $casts = [
        'intigo_cost'      => 'decimal:3',
        'recovered_client' => 'decimal:3',
        'recovered_seller' => 'decimal:3',
        'booked_at'        => 'datetime',
    ];

    protected $appends = ['margin'];

    public function complaint() { return $this->belongsTo(Complaint::class); }

    public function getMarginAttribute(): float
    {
        return round((float) $this->recovered_client + (float) $this->recovered_seller - (float) $this->intigo_cost, 3);
    }
}
