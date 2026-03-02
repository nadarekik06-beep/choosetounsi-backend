<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;
    use HasApiTokens, Notifiable;
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'is_approved',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'is_approved' => 'boolean',
    ];

    // Role checks
    public function sellerProfile() { return $this->hasOne(SellerProfile::class); }
    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isSeller()
    {
        return $this->role === 'seller';
    }

    public function isClient()
    {
        return $this->role === 'client';
    }

    public function isApprovedSeller()
    {
        return $this->isSeller() && $this->is_approved;
    }

    public function isActiveUser()
    {
        return $this->is_active;
    }

    // Relationships
    public function products()
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSellers($query)
    {
        return $query->where('role', 'seller');
    }

    public function scopeApprovedSellers($query)
    {
        return $query->where('role', 'seller')->where('is_approved', true);
    }

    public function scopePendingSellers($query)
    {
        return $query->where('role', 'seller')->where('is_approved', false);
    }

    public function scopeClients($query)
    {
        return $query->where('role', 'client');
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }
     public function sellerApplication()
    {
       return $this->hasOne(SellerApplication::class)->latest();
    }

    public function sellerApplications()
    {
       return $this->hasMany(SellerApplication::class);
    }
}