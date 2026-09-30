<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who did what to a payment request, and when (append-only). */
class PaymentRequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['payment_request_id', 'actor_id', 'actor_role', 'action', 'data'];

    protected $casts = ['data' => 'array'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
