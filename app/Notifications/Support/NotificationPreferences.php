<?php

namespace App\Notifications\Support;

use App\Models\User;

/**
 * Which channels a user gets for each notification category
 * (rules in config/notifications.php → categories):
 *
 *   locked      always on, the user can't turn it off
 *   default_on  on unless the user turned it off
 *   opt_in      promotions e-mail: users.marketing_emails_opt_in + a verified address
 *
 * Choices live in users.notification_preferences as "{category}_{channel}"
 * booleans (orders_email, reviews_in_app…). The two keys of the first version
 * (email_updates / in_app_updates) still count for categories without their own choice.
 */
class NotificationPreferences
{
    public const CHANNELS = ['in_app', 'email'];

    /** Legacy key that applied to every category of that channel. */
    private const LEGACY = ['in_app' => 'in_app_updates', 'email' => 'email_updates'];

    public static function categories(): array
    {
        return array_keys(config('notifications.categories'));
    }

    public static function rule(string $category, string $channel): string
    {
        return config("notifications.categories.{$category}.{$channel}", 'default_on');
    }

    public static function isLocked(string $category, string $channel): bool
    {
        return self::rule($category, $channel) === 'locked';
    }

    /** Preference keys a user may change (PUT /api/profile/notifications). */
    public static function editableKeys(): array
    {
        $keys = [];
        foreach (self::categories() as $category) {
            foreach (self::CHANNELS as $channel) {
                if (!self::isLocked($category, $channel)) $keys[] = "{$category}_{$channel}";
            }
        }
        return $keys;
    }

    public static function allows(User $user, string $category, string $channel): bool
    {
        if (!array_key_exists($category, config('notifications.categories'))) {
            return true;   // seller / admin categories: no buyer preference applies
        }

        if ($channel === 'email' && blank($user->email)) {
            return false;
        }

        return match (self::rule($category, $channel)) {
            'locked' => true,
            'opt_in' => (bool) $user->marketing_emails_opt_in && $user->email_verified_at !== null,
            default  => self::stored($user, "{$category}_{$channel}", self::LEGACY[$channel] ?? null),
        };
    }

    /**
     * Settings as the profile page shows them: one row per category.
     *
     * @return array<int, array{category: string, in_app: array{enabled: bool, locked: bool}, email: array{enabled: bool, locked: bool, opt_in: bool}}>
     */
    public static function settings(User $user): array
    {
        $rows = [];
        foreach (self::categories() as $category) {
            $row = ['category' => $category];
            foreach (self::CHANNELS as $channel) {
                $rule = self::rule($category, $channel);
                $row[$channel] = [
                    'enabled' => match ($rule) {
                        'locked' => true,
                        'opt_in' => (bool) $user->marketing_emails_opt_in,
                        default  => self::stored($user, "{$category}_{$channel}", self::LEGACY[$channel] ?? null),
                    },
                    'locked'  => $rule === 'locked',
                    'opt_in'  => $rule === 'opt_in',
                ];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private static function stored(User $user, string $key, ?string $legacyKey): bool
    {
        $prefs = $user->notification_preferences ?? [];
        if (array_key_exists($key, $prefs)) return (bool) $prefs[$key];
        if ($legacyKey && array_key_exists($legacyKey, $prefs)) return (bool) $prefs[$legacyKey];
        return true;
    }
}
