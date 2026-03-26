<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributeOption extends Model
{
    use HasFactory;

    protected $fillable = ['attribute_id', 'value', 'value_ar', 'color_hex', 'order'];

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }
}