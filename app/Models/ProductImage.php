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
        'image_path',
        'order',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    // ═══════════════════════════════════════════════
    // RELATIONSHIPS
    // ═══════════════════════════════════════════════

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // ═══════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════

    public function getUrlAttribute()
    {
        return Storage::url($this->image_path);
    }

    public function getFullUrlAttribute()
    {
        return url(Storage::url($this->image_path));
    }

    public function setPrimary()
    {
        // Remove primary from other images
        $this->product->images()
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        // Set this as primary
        $this->update(['is_primary' => true]);
    }
}