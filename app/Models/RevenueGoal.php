<?php
// app/Models/RevenueGoal.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RevenueGoal extends Model
{
    protected $fillable = ['seller_id', 'month', 'goal_amount'];

    protected $casts = [
        'goal_amount' => 'float',
    ];
}