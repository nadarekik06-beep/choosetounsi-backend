<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change of a seller's pickup address: old vs new values, who, when. */
class SellerAddressChange extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'old_values'      => 'array',
        'new_values'      => 'array',
        'changed_fields'  => 'array',
        'pending_pickups' => 'integer',
        'created_at'      => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
