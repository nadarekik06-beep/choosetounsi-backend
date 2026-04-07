<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * UserAddress
 *
 * Represents a saved delivery address in a user's address book.
 * Multiple addresses per user are supported; one may be marked as default.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $label       e.g. "Home", "Work"
 * @property string $wilaya
 * @property string $address
 * @property string $phone
 * @property string|null $notes
 * @property bool   $is_default
 */
class UserAddress extends Model
{
    use HasFactory;

    protected $table = 'user_addresses';

    protected $fillable = [
        'user_id',
        'label',
        'wilaya',
        'address',
        'phone',
        'notes',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}