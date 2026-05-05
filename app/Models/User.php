<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'is_approved',
        'google_id',
        'avatar',
        'onboarding_completed',
        // ── Email verification ─────────────────────────────────────────────
        'email_verified_at',
        'email_verification_code',
        'email_verification_expires_at',
        'email_verification_attempts',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',  // Never leak the OTP in JSON responses
    ];

    protected $casts = [
        'email_verified_at'             => 'datetime',
        'email_verification_expires_at' => 'datetime',
        'email_verification_attempts'   => 'integer',
        'is_active'                     => 'boolean',
        'is_approved'                   => 'boolean',
        'onboarding_completed'          => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function sellerProfile()      { return $this->hasOne(SellerProfile::class); }
    public function products()           { return $this->hasMany(Product::class, 'seller_id'); }
    public function orders()             { return $this->hasMany(Order::class); }
    public function sellerApplication()  { return $this->hasOne(SellerApplication::class)->latest(); }
    public function sellerApplications() { return $this->hasMany(SellerApplication::class); }
    public function preferences()
    {
        return $this->hasOne(\App\Models\UserPreference::class);
    }

    public function deliveryAssignments()
    {
        return $this->hasMany(DeliveryAssignment::class, 'delivery_guy_id');
    }

    public function assignedOrders()
    {
        return $this->hasMany(DeliveryAssignment::class, 'assigned_by');
    }

    public function addresses()
    {
        return $this->hasMany(UserAddress::class)
                    ->orderByDesc('is_default')
                    ->orderByDesc('created_at');
    }

    public function defaultAddress()
    {
        return $this->hasOne(UserAddress::class)->where('is_default', true);
    }

    // ── Role helpers ───────────────────────────────────────────────────────

    public function isAdmin()          { return $this->role === 'admin'; }
    public function isSeller()         { return $this->role === 'seller'; }
    public function isClient()         { return $this->role === 'client'; }
    public function isApprovedSeller() { return $this->isSeller() && $this->is_approved; }
    public function isActiveUser()     { return $this->is_active; }
    public function isEmailVerified()  { return (bool) $this->email_verified_at; }
    public function isDeliveryAdmin()  { return $this->role === 'delivery_admin'; }
    public function isDeliveryGuy()    { return $this->role === 'delivery_guy'; }

    public function needsOnboarding(): bool
    {
        return $this->role === 'client' && !$this->onboarding_completed;
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($q)          { return $q->where('is_active', true); }
    public function scopeSellers($q)         { return $q->where('role', 'seller'); }
    public function scopeApprovedSellers($q) { return $q->where('role', 'seller')->where('is_approved', true); }
    public function scopePendingSellers($q)  { return $q->where('role', 'seller')->where('is_approved', false); }
    public function scopeClients($q)         { return $q->where('role', 'client'); }
    public function scopeAdmins($q)          { return $q->where('role', 'admin'); }
    public function scopeDeliveryGuys($q)    { return $q->where('role', 'delivery_guy'); }
    public function scopeVerified($q)        { return $q->whereNotNull('email_verified_at'); }

    public static function getAllAdmins()
    {
        return static::where('role', 'admin')
                     ->where('is_active', true)
                     ->get();
    }
}