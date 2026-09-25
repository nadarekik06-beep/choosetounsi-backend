<?php
// ════════════════════════════════════════════════════════════════════════════
// app/Models/ReviewTag.php
// ════════════════════════════════════════════════════════════════════════════
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewTag extends Model
{
    protected $fillable = ['label', 'label_ar', 'label_fr', 'sentiment', 'icon', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function reviews()
    {
        return $this->belongsToMany(Review::class, 'review_tag_pivot', 'review_tag_id', 'review_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Label in the current locale (label_fr / label_ar), falling back to the base English label.
     * Accepts a model or a plain query-builder row.
     */
    public static function localizedLabel($tag): string
    {
        $locale = app()->getLocale();
        $col    = 'label_' . $locale;

        return ($locale !== 'en' && !empty($tag->{$col} ?? null)) ? $tag->{$col} : (string) $tag->label;
    }
}