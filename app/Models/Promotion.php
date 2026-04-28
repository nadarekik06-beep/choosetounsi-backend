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

    public function scopeActive($q)
    {
        $now = Carbon::now();
        return $q->where('status', 'active')
                 ->where('starts_at', '<=', $now)
                 ->where('ends_at',   '>',  $now);
    }

    public function scopeFlashSales($q) { return $q->where('type', 'flash_sale'); }
    public function scopeDiscounts($q)  { return $q->where('type', 'discount'); }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function isCurrentlyActive(): bool
    {
        return $this->status === 'active'
            && now()->between($this->starts_at, $this->ends_at);
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