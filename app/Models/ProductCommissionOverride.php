<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Admin-set special commission on one product (all variants).
 * See App\Services\CommissionService::rateFor() for how it ranks, and
 * App\Services\ProductCommissionOverrideService for how it is written.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $seller_id
 * @property string $type   fixed_rate | reduction_points
 * @property float $value
 */
class ProductCommissionOverride extends Model
{
    use SoftDeletes;

    public const TYPE_FIXED     = 'fixed_rate';
    public const TYPE_REDUCTION = 'reduction_points';
    public const TYPES          = [self::TYPE_FIXED, self::TYPE_REDUCTION];

    protected $fillable = [
        'product_id', 'seller_id', 'type', 'value', 'starts_at', 'ends_at', 'note', 'is_active',
        'created_by', 'updated_by', 'granted_notified_at', 'reminder_sent_at', 'ended_notified_at',
    ];

    protected $casts = [
        'value'               => 'float',
        'is_active'           => 'boolean',
        'starts_at'           => 'datetime',
        'ends_at'             => 'datetime',
        'granted_notified_at' => 'datetime',
        'reminder_sent_at'    => 'datetime',
        'ended_notified_at'   => 'datetime',
    ];

    public function product()  { return $this->belongsTo(Product::class); }
    public function seller()   { return $this->belongsTo(User::class, 'seller_id'); }
    public function creator()  { return $this->belongsTo(User::class, 'created_by'); }

    /** Live at $at: switched on, started (starts_at <= at) and not over (at < ends_at). Date comparison only, never the cron. */
    public function scopeLiveAt($q, CarbonInterface $at)
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public function isLiveAt(CarbonInterface $at): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lte($at))
            && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    /** active (live now) | scheduled | expired | ended (switched off) */
    public function statusAt(CarbonInterface $at): string
    {
        if (!$this->is_active) return 'ended';
        if ($this->ends_at !== null && $this->ends_at->lte($at)) return 'expired';
        if ($this->starts_at !== null && $this->starts_at->gt($at)) return 'scheduled';
        return 'active';
    }

    /** Short human label, e.g. "5%" or "−3 pts". */
    public function valueLabel(): string
    {
        $v = rtrim(rtrim(number_format($this->value, 2, '.', ''), '0'), '.');
        return $this->type === self::TYPE_FIXED ? "{$v}%" : "−{$v} pts";
    }
}
