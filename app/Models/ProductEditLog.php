<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One admin save in the product editor: who changed what, and when. */
class ProductEditLog extends Model
{
    protected $fillable = ['product_id', 'admin_id', 'summary', 'changes'];

    protected $casts = [
        'changes' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
