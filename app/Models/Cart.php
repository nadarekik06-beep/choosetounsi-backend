<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'product_id', 'quantity'];

    /* ── Relationships ── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->with('primaryImage');
    }

    /* ── Helpers ── */

    /** Computed line total */
    public function getLineTotalAttribute(): float
    {
        return round((float) $this->product->price * $this->quantity, 3);
    }
}