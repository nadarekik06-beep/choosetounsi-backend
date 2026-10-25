<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One column of a gendered mega-menu (e.g. Femme → Vêtements). */
class GenderMenuGroup extends Model
{
    protected $fillable = ['category_id', 'gender', 'key', 'name', 'name_fr', 'name_ar', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function items()
    {
        return $this->hasMany(GenderMenuItem::class, 'group_id')->orderBy('sort_order')->orderBy('id');
    }
}
