<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Goal alert preferences of a seller (Centre de profit → 🔔). */
class ProfitAlertSetting extends Model
{
    public const TOGGLES = [
        'enabled', 'milestones', 'pace', 'weekly', 'monthly_recap', 'new_goal_reminder', 'channel_bell', 'channel_email',
    ];

    protected $fillable = [
        'seller_id', 'enabled', 'milestones', 'pace', 'weekly', 'monthly_recap', 'new_goal_reminder',
        'channel_bell', 'channel_email', 'last_weekly_on', 'last_recap_month', 'last_reminder_month',
    ];

    protected $casts = [
        'enabled' => 'boolean', 'milestones' => 'boolean', 'pace' => 'boolean', 'weekly' => 'boolean',
        'monthly_recap' => 'boolean', 'new_goal_reminder' => 'boolean',
        'channel_bell' => 'boolean', 'channel_email' => 'boolean',
        'last_weekly_on' => 'date',
    ];

    /** Saved settings, or an unsaved row with the config defaults. */
    public static function forSeller(int $sellerId): self
    {
        return static::firstWhere('seller_id', $sellerId)
            ?? new static(['seller_id' => $sellerId] + config('profit.alert_defaults'));
    }

    /** Is this alert kind on, with at least one channel? */
    public function wants(string $kind): bool
    {
        return $this->enabled && $this->{$kind} && ($this->channel_bell || $this->channel_email);
    }

    public function toPublic(): array
    {
        return collect(self::TOGGLES)->mapWithKeys(fn($k) => [$k => (bool) $this->{$k}])->all();
    }
}
