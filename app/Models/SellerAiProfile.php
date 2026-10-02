<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's saved store voice for AI-written product copy (Black Pepper).
 *
 * @property int         $user_id
 * @property string|null $brand_voice
 * @property array|null  $brand_keywords
 */
class SellerAiProfile extends Model
{
    protected $fillable = ['user_id', 'brand_voice', 'brand_keywords'];

    protected $casts = [
        'brand_keywords' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
