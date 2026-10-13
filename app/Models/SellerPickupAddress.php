<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's pickup address, 1:1 with Intigo's "Nouvelle adresse" form.
 * Written only through App\Services\Sellers\PickupAddressService (history,
 * flags, admin notice). See the migration for the status columns.
 */
class SellerPickupAddress extends Model
{
    public const STATUS_NEEDS_REVIEW = 'needs_review';   // no zone / no map pin yet
    public const STATUS_CHANGED      = 'changed';        // changed: Intigo must be updated
    public const STATUS_UNVERIFIED   = 'unverified';
    public const STATUS_VERIFIED     = 'verified';       // checked, not at Intigo yet
    public const STATUS_CREATED      = 'created';        // verified and created at Intigo

    /** The address fields (history diff, change detection), in the Intigo form order. */
    public const FIELDS = ['adresse_complete', 'intigo_zone_id', 'latitude', 'longitude', 'contact_name', 'phone', 'code_postal', 'indications'];

    protected $guarded = ['id'];

    protected $casts = [
        'latitude'                  => 'float',
        'longitude'                 => 'float',
        'needs_review'              => 'boolean',
        'change_pending'            => 'boolean',
        'address_verified_by_admin' => 'boolean',
        'intigo_pickup_created'     => 'boolean',
        'verified_at'               => 'datetime',
        'intigo_pickup_created_at'  => 'datetime',
        'last_address_change_at'    => 'datetime',
        'legacy_snapshot'           => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function zone()
    {
        return $this->belongsTo(IntigoZone::class, 'intigo_zone_id');
    }

    public function changes()
    {
        return $this->hasMany(SellerAddressChange::class, 'user_id', 'user_id')->orderByDesc('id');
    }

    /** Zone and map pin set: what Intigo's form needs. */
    public function isLocated(): bool
    {
        return $this->intigo_zone_id !== null && $this->latitude !== null && $this->longitude !== null
            && trim((string) $this->adresse_complete) !== '';
    }

    /** Parcels may go to Intigo: verified by the admin and created there. */
    public function isIntigoReady(): bool
    {
        return $this->isLocated() && $this->address_verified_by_admin && $this->intigo_pickup_created && !$this->change_pending;
    }

    public function status(): string
    {
        if (!$this->isLocated()) return self::STATUS_NEEDS_REVIEW;
        if ($this->change_pending && !$this->intigo_pickup_created) return self::STATUS_CHANGED;
        if (!$this->address_verified_by_admin) return self::STATUS_UNVERIFIED;
        if (!$this->intigo_pickup_created) return self::STATUS_VERIFIED;
        return self::STATUS_CREATED;
    }

    /** SQL filter for one status() value (admin sellers list). */
    public function scopeWhereStatus($query, string $status)
    {
        $located = fn ($q) => $q->whereNotNull('intigo_zone_id')->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNotNull('adresse_complete')->where('adresse_complete', '!=', '');

        return match ($status) {
            self::STATUS_NEEDS_REVIEW => $query->where(fn ($q) => $q->whereNull('intigo_zone_id')->orWhereNull('latitude')
                ->orWhereNull('longitude')->orWhereNull('adresse_complete')->orWhere('adresse_complete', '')),
            self::STATUS_CHANGED    => $query->where($located)->where('change_pending', true)->where('intigo_pickup_created', false),
            self::STATUS_UNVERIFIED => $query->where($located)->where(fn ($q) => $q->where('change_pending', false)->orWhere('intigo_pickup_created', true))
                ->where('address_verified_by_admin', false),
            self::STATUS_VERIFIED   => $query->where($located)->where('change_pending', false)->where('address_verified_by_admin', true)->where('intigo_pickup_created', false),
            self::STATUS_CREATED    => $query->where($located)->where('address_verified_by_admin', true)->where('intigo_pickup_created', true),
            default                 => $query,
        };
    }
}
