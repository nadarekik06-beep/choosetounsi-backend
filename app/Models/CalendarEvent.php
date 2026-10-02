<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tunisian calendar event (Ramadan, Aïd, rentrée, soldes…), admin-editable.
 * Data only: it marks the forecast timeline and drives reminders. An event
 * changes forecast numbers only through a measured effect (CalendarEventEffect).
 */
class CalendarEvent extends Model
{
    protected $fillable = [
        'key', 'name_fr', 'name_en', 'name_ar', 'starts_on', 'ends_on', 'category_ids', 'is_active', 'source',
    ];

    protected $casts = [
        'starts_on'    => 'date:Y-m-d',
        'ends_on'      => 'date:Y-m-d',
        'category_ids' => 'array',
        'is_active'    => 'boolean',
    ];

    public function effects()
    {
        return $this->hasMany(CalendarEventEffect::class);
    }

    public function names(): array
    {
        return ['fr' => $this->name_fr, 'en' => $this->name_en, 'ar' => $this->name_ar];
    }

    public function appliesTo(int $categoryId): bool
    {
        return empty($this->category_ids) || in_array($categoryId, array_map('intval', $this->category_ids), true);
    }
}
