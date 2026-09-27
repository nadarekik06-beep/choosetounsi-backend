<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One seller save on a product: the fields it changed, grouped for one admin notification. */
class ProductChangeSet extends Model
{
    protected $fillable = [
        'product_id', 'seller_id', 'source', 'summary', 'groups', 'is_sensitive',
        'sensitive_reasons', 'stock_only', 'notified', 'reverted_at', 'reverted_by',
    ];

    protected $casts = [
        'groups'            => 'array',
        'sensitive_reasons' => 'array',
        'is_sensitive'      => 'boolean',
        'stock_only'        => 'boolean',
        'notified'          => 'boolean',
        'reverted_at'       => 'datetime',
    ];

    public function product()  { return $this->belongsTo(Product::class)->withTrashed(); }
    public function seller()   { return $this->belongsTo(User::class, 'seller_id'); }
    public function reverter() { return $this->belongsTo(User::class, 'reverted_by'); }
    public function items()    { return $this->hasMany(ProductChangeItem::class, 'change_set_id')->orderBy('id'); }
}
