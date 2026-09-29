<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Campaign × Africa/Tunis day × placement totals (rebuilt from sponsorship_events). */
class SponsorshipDailyStat extends Model
{
    protected $fillable = ['sponsorship_id', 'date', 'placement', 'impressions', 'clicks', 'cost', 'orders', 'revenue'];

    protected $casts = [
        'date'        => 'date:Y-m-d',
        'impressions' => 'integer',
        'clicks'      => 'integer',
        'orders'      => 'integer',
        'cost'        => 'decimal:3',
        'revenue'     => 'decimal:3',
    ];

    public function sponsorship()
    {
        return $this->belongsTo(Sponsorship::class);
    }
}
