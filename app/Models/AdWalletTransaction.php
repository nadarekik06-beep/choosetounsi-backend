<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ad wallet ledger row. amount = signed total; credit_amount = signed part on the
 * free credit (paid part = amount − credit_amount). click_charge rows are
 * rolled up per campaign per Africa/Tunis day (rollup_date).
 */
class AdWalletTransaction extends Model
{
    public const TYPE_TOP_UP         = 'top_up';
    public const TYPE_MONTHLY_CREDIT = 'monthly_credit';
    public const TYPE_CLICK_CHARGE   = 'click_charge';
    public const TYPE_REFUND         = 'refund';
    public const TYPE_ADMIN_ADJUST   = 'admin_adjust';
    public const TYPE_CREDIT_EXPIRY  = 'credit_expiry';

    protected $fillable = [
        'seller_id', 'type', 'amount', 'credit_amount', 'balance_after', 'credit_after',
        'sponsorship_id', 'rollup_date', 'reference', 'meta', 'created_by',
    ];

    protected $casts = [
        'amount'        => 'decimal:3',
        'credit_amount' => 'decimal:3',
        'balance_after' => 'decimal:3',
        'credit_after'  => 'decimal:3',
        'rollup_date'   => 'date:Y-m-d',
        'meta'          => 'array',
    ];

    public function sponsorship()
    {
        return $this->belongsTo(Sponsorship::class);
    }

    /** The part that moved on the paid balance. */
    public function paidAmount(): float
    {
        return round((float) $this->amount - (float) $this->credit_amount, 3);
    }
}
