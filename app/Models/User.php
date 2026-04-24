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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active'         => 'boolean',
        'is_approved'       => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function sellerProfile()      { return $this->hasOne(SellerProfile::class); }
    public function products()           { return $this->hasMany(Product::class, 'seller_id'); }
    public function orders()             { return $this->hasMany(Order::class); }
    public function sellerApplication()  { return $this->hasOne(SellerApplication::class)->latest(); }
    public function sellerApplications() { return $this->hasMany(SellerApplication::class); }
    // In User.php — add to the relationships section

    /** Delivery assignments for delivery_guy users */
    public function deliveryAssignments()
    {
        return $this->hasMany(DeliveryAssignment::class, 'delivery_guy_id');
    }

    /** Orders assigned BY this user (delivery_admin) */
    public function assignedOrders()
    {
        return $this->hasMany(DeliveryAssignment::class, 'assigned_by');
    }

    // Role helpers — add alongside existing ones
    public function isDeliveryAdmin() { return $this->role === 'delivery_admin'; }
    public function isDeliveryGuy()   { return $this->role === 'delivery_guy'; }
        /**
     * User's saved delivery addresses (address book).
     * Ordered so the default address always comes first.
     */
    public function addresses()
    {
        return $this->hasMany(UserAddress::class)
                    ->orderByDesc('is_default')
                    ->orderByDesc('created_at');
    }

    /**
     * Convenience: returns the user's default address or null.
     */
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

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($q)          { return $q->where('is_active', true); }
    public function scopeSellers($q)         { return $q->where('role', 'seller'); }
    public function scopeApprovedSellers($q) { return $q->where('role', 'seller')->where('is_approved', true); }
    public function scopePendingSellers($q)  { return $q->where('role', 'seller')->where('is_approved', false); }
    public function scopeClients($q)         { return $q->where('role', 'client'); }
    public function scopeAdmins($q)          { return $q->where('role', 'admin'); }
    public function scopeDeliveryGuys($q)
    {
        return $q->where('role', 'delivery_guy');
    }
    public static function getAllAdmins()
    {
        return static::where('role', 'admin')
                     ->where('is_active', true)
                     ->get();
    }
}