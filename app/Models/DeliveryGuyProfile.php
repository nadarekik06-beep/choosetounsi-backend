<?php
// app/Models/DeliveryGuyProfile.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryGuyProfile extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'wilaya',
        'city',
        'vehicle_type',
        'vehicle_plate',
        'id_card_number',
        'is_available',
    ];

    protected $casts = [
        'is_available' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}