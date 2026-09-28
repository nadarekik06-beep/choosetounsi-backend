<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInterestProfile extends Model
{
    protected $fillable = ['user_id', 'session_id', 'profile', 'signal_count', 'computed_at'];

    protected $casts = [
        'profile'      => 'array',
        'signal_count' => 'integer',
        'computed_at'  => 'datetime',
    ];
}
