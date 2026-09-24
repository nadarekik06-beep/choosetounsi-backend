<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Key / JSON-value platform settings editable by admins. */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = ['value' => 'array'];

    private static array $cache = [];

    public static function getValue(string $key, $default = null)
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = optional(self::where('key', $key)->first())->value;
        }
        return self::$cache[$key] ?? $default;
    }

    public static function setValue(string $key, $value, ?int $userId = null): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        self::$cache[$key] = $value;
    }
}
