<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// ════════════════════════════════════════════════════════════════════════════
// ReviewVote — "Was this review helpful?"
// ════════════════════════════════════════════════════════════════════════════
class ReviewVote extends Model
{
    protected $fillable = ['review_id', 'user_id', 'type'];

    public function review()
    {
        return $this->belongsTo(Review::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}