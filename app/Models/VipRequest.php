<?php
// app/Models/VipRequest.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VipRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'message',
        'admin_note',
        'handled_by',
        'handled_at',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopePending($q)    { return $q->where('status', 'pending'); }
    public function scopeInProgress($q) { return $q->where('status', 'in_progress'); }
    public function scopeCompleted($q)  { return $q->where('status', 'completed'); }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function getTypeLabelAttribute(): string
    {
        $key = "seller.labels.vip_type.{$this->type}";
        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : (string) $this->type;
    }

    public function getStatusLabelAttribute(): string
    {
        $key = "seller.labels.vip_status.{$this->status}";
        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : (string) $this->status;
    }
}