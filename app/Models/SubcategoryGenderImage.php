<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Admin image for the round thumbnail of a subcategory × gender (visual subcategory bar). */
class SubcategoryGenderImage extends Model
{
    protected $fillable = ['subcategory_id', 'gender', 'image_path'];

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }
}
