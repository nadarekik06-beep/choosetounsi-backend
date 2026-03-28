<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',        // nullable — links to a specific variant
        'color_option_id',   // nullable — links to a color option (shared across variants)
        'image_path',
        'order',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function colorOption()
    {
        return $this->belongsTo(AttributeOption::class, 'color_option_id');
    }

    // ── Accessors ──────────────────────────────────────────────────────────────

    public function getUrlAttribute(): string
    {
        return Storage::url($this->image_path);
    }

    public function getFullUrlAttribute(): string
    {
        return url(Storage::url($this->image_path));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function setPrimary(): void
    {
        $this->product->images()
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->update(['is_primary' => true]);
    }
}