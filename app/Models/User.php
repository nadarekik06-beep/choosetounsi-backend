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
        'google_id',   // Google OAuth ID
        'avatar',      // Google profile photo URL
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

    /* ── Relationships ── */
    public function sellerProfile()   { return $this->hasOne(SellerProfile::class); }
    public function products()        { return $this->hasMany(Product::class, 'seller_id'); }
    public function orders()          { return $this->hasMany(Order::class); }
    public function sellerApplication()  { return $this->hasOne(SellerApplication::class)->latest(); }
    public function sellerApplications() { return $this->hasMany(SellerApplication::class); }

    /* ── Role helpers ── */
    public function isAdmin()          { return $this->role === 'admin'; }
    public function isSeller()         { return $this->role === 'seller'; }
    public function isClient()         { return $this->role === 'client'; }
    public function isApprovedSeller() { return $this->isSeller() && $this->is_approved; }
    public function isActiveUser()     { return $this->is_active; }

    /* ── Scopes ── */
    public function scopeActive($q)          { return $q->where('is_active', true); }
    public function scopeSellers($q)         { return $q->where('role', 'seller'); }
    public function scopeApprovedSellers($q) { return $q->where('role', 'seller')->where('is_approved', true); }
    public function scopePendingSellers($q)  { return $q->where('role', 'seller')->where('is_approved', false); }
    public function scopeClients($q)         { return $q->where('role', 'client'); }
    public function scopeAdmins($q)          { return $q->where('role', 'admin'); }
}