<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A subcategory placed in a gender menu column. */
class GenderMenuItem extends Model
{
    protected $fillable = ['group_id', 'subcategory_id', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function group()
    {
        return $this->belongsTo(GenderMenuGroup::class, 'group_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }
}
