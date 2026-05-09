<?php
// app/Models/DeliveryCompanyProfile.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryCompanyProfile extends Model
{
    protected $fillable = [
        'user_id',
        'company_name',
        'phone',
        'address',
        'wilaya',
        'city',
        'website',
        'registration_number',
        'logo_url',
        'description',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}