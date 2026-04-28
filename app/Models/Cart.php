<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        // ── Product row fields ──
        'product_id',
        'variant_id',
        // ── Pack row fields (new) ──
        'pack_id',
        'pack_price_snapshot',
        'pack_name',
        'pack_selections',
        // ── Shared ──
        'quantity',
    ];

    protected $casts = [
        'quantity'             => 'integer',
        'variant_id'           => 'integer',
        'pack_id'              => 'integer',
        'pack_price_snapshot'  => 'float',
        'pack_selections'      => 'array',   // auto JSON encode/decode
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    // New: pack relationship
    public function pack()
    {
        return $this->belongsTo(Pack::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Returns true if this cart row represents a pack bundle,
     * false if it represents a regular product.
     */
    public function isPack(): bool
    {
        return $this->pack_id !== null;
    }
}