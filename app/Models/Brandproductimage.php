<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BrandProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_product_id',
        'image_path',
        'order',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function brandProduct()
    {
        return $this->belongsTo(BrandProduct::class);
    }

    // ── Accessors ──────────────────────────────────────────────────────────────

    public function getUrlAttribute(): string
    {
        return Storage::url($this->image_path);
    }
}