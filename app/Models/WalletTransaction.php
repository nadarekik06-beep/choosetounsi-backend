<?php
// app/Models/WalletTransaction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'user_id', 'amount', 'type', 'reason',
        'order_id', 'note', 'balance_after',
    ];

    protected $casts = [
        'amount'        => 'decimal:3',
        'balance_after' => 'decimal:3',
    ];

    public function user()  { return $this->belongsTo(User::class); }
    public function order() { return $this->belongsTo(Order::class); }
}