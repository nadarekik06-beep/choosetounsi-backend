<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Pack extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'name',
        'slug',
        'description',
        'short_description',
        'image_path',
        'pack_price',
        'original_price',
        'is_active',
        'is_approved',
        'views',
    ];

    protected $casts = [
        'pack_price'     => 'decimal:3',
        'original_price' => 'decimal:3',
        'is_active'      => 'boolean',
        'is_approved'    => 'boolean',
    ];

    protected $appends = ['image_url', 'savings', 'available_stock'];

    // ── Boot ──────────────────────────────────────────────────────────────

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($pack) {
            if (empty($pack->slug)) {
                $pack->slug = static::uniqueSlug($pack->name);
            }
        });

        static::deleting(function ($pack) {
            if ($pack->image_path) {
                Storage::disk('public')->delete($pack->image_path);
            }
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * Pack items, ordered for display.
     */
    public function items()
    {
        return $this->hasMany(PackItem::class)->orderBy('order');
    }

    /**
     * Items with full product + variant + image data eager-loaded.
     */
public function itemsWithDetails()
{
    return $this->hasMany(PackItem::class)
        ->orderBy('order')
        ->with([
            'product:id,name,slug,price,is_active,is_approved',
            'product.primaryImage',
            // ← 'variant' REMOVED — pack items no longer have a single variant FK
        ]);
}

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($q)    { return $q->where('is_active', true); }
    public function scopeApproved($q)  { return $q->where('is_approved', true); }
    public function scopeAvailable($q) { return $q->where('is_active', true)->where('is_approved', true); }

    // ── Accessors ──────────────────────────────────────────────────────────

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? Storage::url($this->image_path)
            : null;
    }

    /**
     * How much the buyer saves compared to buying items individually.
     * original_price - pack_price  (cached on save, always ≥ 0)
     */
    public function getSavingsAttribute(): float
    {
        return max(0, (float) $this->original_price - (float) $this->pack_price);
    }

    /**
     * Pack is available only if every item has sufficient stock.
     * Returns the minimum purchasable quantity (like a bundle minimum).
     * 0 = out of stock.
     *
     * NOTE: only meaningful when items are loaded.
     */
    public function getAvailableStockAttribute(): int
    {
        if (!$this->relationLoaded('items')) return 0;

        $min = PHP_INT_MAX;

        foreach ($this->items as $item) {
            $stock = $item->effectiveStock();
            // Floor divide by quantity required per pack
            $units = $item->quantity > 0
                ? intdiv($stock, $item->quantity)
                : 0;
            $min = min($min, $units);
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->is_active && $this->is_approved;
    }

    /**
     * Recalculate and persist original_price from current items.
     * Call after adding/removing items.
     */
    public function recalculateOriginalPrice(): void
{
    $total = $this->items()
        ->with(['product:id,price'])  // ← REMOVE 'variant' from here
        ->get()
        ->sum(function ($item) {
            // No variant FK anymore — use product base price for snapshot
            return (float) $item->product->price * $item->quantity;
        });

    $this->original_price = $total;
    $this->saveQuietly();
}

  protected static function uniqueSlug(string $name, ?int $excludeId = null): string
{
    $base = Str::slug($name);
    $slug = $base;
    $i    = 1;
    while (true) {
        $q = static::where('slug', $slug);
        if ($excludeId) $q->where('id', '!=', $excludeId);
        if (!$q->exists()) break;
        $slug = $base . '-' . (++$i);
    }
    return $slug;
}
}