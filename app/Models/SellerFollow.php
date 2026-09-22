<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerFollow extends Model
{
    protected $fillable = [
        'user_id',
        'seller_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
