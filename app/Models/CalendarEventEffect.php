<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Sales change measured in one category during one past event (see EventEffectMeasurer). */
class CalendarEventEffect extends Model
{
    protected $fillable = [
        'calendar_event_id', 'category_id', 'event_daily_units', 'baseline_daily_units', 'change_pct',
        'event_orders', 'baseline_orders', 'event_days', 'baseline_days',
    ];

    protected $casts = [
        'event_daily_units'    => 'float',
        'baseline_daily_units' => 'float',
        'change_pct'           => 'float',
        'event_orders'         => 'integer',
        'baseline_orders'      => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    /** Enough orders on both sides to be used in forecasts (config forecast.event_effect_min_orders). */
    public function isReliable(): bool
    {
        $min = (int) config('forecast.event_effect_min_orders');
        return $this->change_pct !== null && $this->event_orders >= $min && $this->baseline_orders >= $min;
    }
}
