<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Promotion extends Model
{
    protected $fillable = [
        'seller_id', 'name', 'type', 'discount_type', 'discount_value',
        'starts_at', 'ends_at', 'flash_stock', 'flash_stock_used',
        'status', 'priority',
    ];

    protected $casts = [
        'starts_at'        => 'datetime',
        'ends_at'          => 'datetime',
        'discount_value'   => 'decimal:3',
        'flash_stock'      => 'integer',
        'flash_stock_used' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'promotion_products');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /**
     * Statuses under which a promotion prices products. Dates decide the rest, so
     * pricing never waits on `promotions:sync` to flip scheduled → active → expired.
     */
    public const PRICING_STATUSES = ['active', 'scheduled'];

    /** Pricing products right now: live status, inside its window, flash quota left. */
    public function scopeActive($q)
    {
        $now   = Carbon::now();
        $table = $q->getModel()->getTable();
        return $q->whereIn("{$table}.status", self::PRICING_STATUSES)
                 ->where("{$table}.starts_at", '<=', $now)
                 ->where("{$table}.ends_at",   '>',  $now)
                 ->where(fn ($q) => $q->whereNull("{$table}.flash_stock")
                     ->orWhereColumn("{$table}.flash_stock_used", '<', "{$table}.flash_stock"));
    }

    public function scopeFlashSales($q) { return $q->where('type', 'flash_sale'); }
    public function scopeDiscounts($q)  { return $q->where('type', 'discount'); }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Same rule as scopeActive(), for an already loaded promotion. */
    public function isCurrentlyActive(): bool
    {
        return in_array($this->status, self::PRICING_STATUSES, true)
            && $this->starts_at <= now() && $this->ends_at > now()
            && $this->hasFlashStockRemaining();
    }

    public function hasFlashStockRemaining(): bool
    {
        if ($this->flash_stock === null) return true;
        return ($this->flash_stock - $this->flash_stock_used) > 0;
    }

    public function flashStockRemaining(): ?int
    {
        if ($this->flash_stock === null) return null;
        return max(0, $this->flash_stock - $this->flash_stock_used);
    }
}