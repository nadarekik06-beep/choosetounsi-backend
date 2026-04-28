<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'pack_id',
        'product_id',
        'allowed_variant_ids',   // ← replaces variant_id
        'quantity',
        'unit_price_snapshot',
        'order',
    ];

    protected $casts = [
        'quantity'            => 'integer',
        'unit_price_snapshot' => 'decimal:3',
        'order'               => 'integer',
        'allowed_variant_ids' => 'array',    // ← auto JSON encode/decode
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function pack()
    {
        return $this->belongsTo(Pack::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The variants the client can choose from for this item.
     * If allowed_variant_ids is null → all active variants of the product.
     */
    public function availableVariants()
    {
        $query = ProductVariant::where('product_id', $this->product_id)
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->with(['attributeOptions.attribute:id,slug,name,type']);

        if (!empty($this->allowed_variant_ids)) {
            $query->whereIn('id', $this->allowed_variant_ids);
        }

        return $query->get();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Minimum stock across allowed variants (for pack availability).
     * Each variant contributes floor(stock / quantity).
     */
    public function effectiveStock(): int
    {
        $variants = $this->availableVariants();

        if ($variants->isEmpty()) {
            // Simple product (no variants)
            return (int) $this->product->stock;
        }

        // The client picks ONE variant — so the item is available
        // if ANY variant has enough stock
        return (int) $variants->max('stock');
    }

    /**
     * Base price for savings calculation (lowest variant price or product price).
     */
    public function effectiveUnitPrice(): float
    {
        if ($this->product->variants()->exists()) {
            $variants = $this->availableVariants();
            if ($variants->isNotEmpty()) {
                $prices = $variants->map(fn($v) =>
                    $v->price_override !== null
                        ? (float) $v->price_override
                        : (float) $this->product->price
                );
                return (float) $prices->min(); // use lowest for snapshot
            }
        }
        return (float) $this->product->price;
    }

    public function lineTotal(): float
    {
        return $this->effectiveUnitPrice() * $this->quantity;
    }
}