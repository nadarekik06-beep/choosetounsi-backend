<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    use HasFactory;

    protected $table = 'user_preferences';

    protected $fillable = [
        'user_id',
        'gender',
        'category_ids',
        'brand_ids',
        'price_min',
        'price_max',
    ];

    protected $casts = [
        'category_ids' => 'array',
        'brand_ids'    => 'array',
        'price_min'    => 'decimal:3',
        'price_max'    => 'decimal:3',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Returns category_ids as a clean integer array.
     * Guards against null and non-integer values.
     */
    public function getCategoryIdsArrayAttribute(): array
    {
        return array_map('intval', (array) ($this->category_ids ?? []));
    }

    /**
     * Returns brand_ids as a clean integer array.
     */
    public function getBrandIdsArrayAttribute(): array
    {
        return array_map('intval', (array) ($this->brand_ids ?? []));
    }

    /**
     * True if the user set any preferences.
     */
    public function hasAnyPreference(): bool
    {
        return !empty($this->category_ids)
            || !empty($this->brand_ids)
            || !is_null($this->gender)
            || !is_null($this->price_min)
            || !is_null($this->price_max);
    }
}