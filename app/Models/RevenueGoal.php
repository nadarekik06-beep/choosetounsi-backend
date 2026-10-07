<?php
// app/Models/RevenueGoal.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's monthly goal (Centre de profit). One row per seller and month
 * ("2026-10", Africa/Tunis). goal_amount is confirmed sales in DT; orders and
 * net earnings targets are optional.
 */
class RevenueGoal extends Model
{
    protected $fillable = [
        'seller_id', 'month', 'goal_amount', 'orders_target', 'net_target', 'preset',
        'milestones_sent', 'last_pace_alert_at',
        'achieved_revenue', 'achieved_orders', 'achieved_net', 'closed_at',
    ];

    protected $casts = [
        'goal_amount'        => 'float',
        'orders_target'      => 'integer',
        'net_target'         => 'float',
        'milestones_sent'    => 'array',
        'last_pace_alert_at' => 'datetime',
        'achieved_revenue'   => 'float',
        'achieved_orders'    => 'integer',
        'achieved_net'       => 'float',
        'closed_at'          => 'datetime',
    ];
}
