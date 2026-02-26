<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SellerApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'full_name',
        'phone_number',
        'business_name',
        'business_category',
        'business_description',
        'wilaya',
        'city',
        'profile_picture',
        'sample_images',
        'facebook_url',
        'instagram_url',
        'website_url',
        'status',
        'rejection_reason',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'sample_images' => 'array',
        'reviewed_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════
    // RELATIONSHIPS
    // ═══════════════════════════════════════════════

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ═══════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    // ═══════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isApproved()
    {
        return $this->status === 'approved';
    }

    public function isRejected()
    {
        return $this->status === 'rejected';
    }

    public function approve($adminId)
    {
        $this->update([
            'status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => $adminId,
            'rejection_reason' => null,
        ]);

        // Update user role to seller
        $this->user->update([
            'role' => 'seller',
            'is_approved' => true,
        ]);
    }

    public function reject($adminId, $reason)
    {
        $this->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by' => $adminId,
            'rejection_reason' => $reason,
        ]);
    }
}