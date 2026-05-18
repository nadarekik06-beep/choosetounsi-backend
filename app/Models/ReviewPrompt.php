<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewPrompt extends Model
{
    protected $fillable = [
        'user_id', 'order_item_id', 'product_id',
        'sent_at', 'dismissed_at', 'reviewed_at', 'channel',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'dismissed_at' => 'datetime',
        'reviewed_at'  => 'datetime',
    ];

    public function user()    { return $this->belongsTo(User::class); }
    public function orderItem(){ return $this->belongsTo(OrderItem::class, 'order_item_id'); }
    public function product() { return $this->belongsTo(Product::class); }

    public function isPending(): bool
    {
        return is_null($this->reviewed_at);
    }
}