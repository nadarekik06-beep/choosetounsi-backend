<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewReply extends Model
{
    protected $fillable = ['review_id', 'seller_id', 'body', 'is_visible'];

    protected $casts = ['is_visible' => 'boolean'];

    public function review()
    {
        return $this->belongsTo(Review::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}