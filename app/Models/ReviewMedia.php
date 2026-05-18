<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ReviewMedia extends Model
{
    use SoftDeletes;

    protected $fillable = ['review_id', 'path', 'type', 'sort_order', 'is_approved'];

    protected $casts = ['is_approved' => 'boolean', 'sort_order' => 'integer'];

    protected $appends = ['url'];

    public function review()
    {
        return $this->belongsTo(Review::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::url($this->path);
    }
}