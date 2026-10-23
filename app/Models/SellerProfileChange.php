<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change of a seller's logo, description or activity categories: old vs new, who, when. */
class SellerProfileChange extends Model
{
    public const UPDATED_AT = null;

    public const LOGO        = 'logo';
    public const DESCRIPTION = 'description';
    public const CATEGORIES  = 'categories';
    public const FIELDS      = [self::LOGO, self::DESCRIPTION, self::CATEGORIES];

    protected $guarded = ['id'];

    protected $casts = [
        'old_value'  => 'array',
        'new_value'  => 'array',
        'created_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
