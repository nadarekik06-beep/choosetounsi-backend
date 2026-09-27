<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One changed field inside a ProductChangeSet (old → new). */
class ProductChangeItem extends Model
{
    protected $fillable = [
        'change_set_id', 'field', 'group', 'label', 'old_value', 'new_value',
        'is_sensitive', 'revertible', 'reverted_at',
    ];

    protected $casts = [
        'old_value'    => 'array',
        'new_value'    => 'array',
        'is_sensitive' => 'boolean',
        'revertible'   => 'boolean',
        'reverted_at'  => 'datetime',
    ];

    public function changeSet() { return $this->belongsTo(ProductChangeSet::class, 'change_set_id'); }
}
