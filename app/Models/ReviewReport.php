<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewReport extends Model
{
    protected $fillable = ['review_id', 'reported_by', 'reason', 'note', 'status'];

    public function review()
    {
        return $this->belongsTo(Review::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}