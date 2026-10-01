<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Season / Occasion tag of a product (values: App\Support\Occasions). */
class ProductOccasion extends Model
{
    protected $table      = 'product_occasions';
    public $timestamps    = false;
    protected $fillable   = ['product_id', 'occasion'];

    public function product() { return $this->belongsTo(Product::class); }
}
