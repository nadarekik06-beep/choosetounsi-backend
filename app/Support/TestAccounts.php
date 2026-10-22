<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The TEST accounts used for smoke tests on production
 * (choosetounsi+testbuyer / +testseller / +testadmin @gmail.com, users 8-10).
 * Their orders must never reach a real courier: ParcelPayload refuses them
 * in live mode.
 *
 * config intigo.test_accounts: email_pattern (regex) + user_ids (extra ids).
 */
class TestAccounts
{
    /** @var array<int, bool> per-request cache */
    private static array $cache = [];

    public static function isTestEmail(?string $email): bool
    {
        $pattern = (string) config('intigo.test_accounts.email_pattern');
        return $email !== null && $pattern !== '' && preg_match($pattern, trim($email)) === 1;
    }

    public static function isTestUser(?int $userId): bool
    {
        if (!$userId) {
            return false;
        }
        if (in_array($userId, self::ids(), true)) {
            return true;
        }
        return self::$cache[$userId] ??= self::isTestEmail(DB::table('users')->where('id', $userId)->value('email'));
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return int[] */
    private static function ids(): array
    {
        $raw = config('intigo.test_accounts.user_ids', []);
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        return array_values(array_map('intval', array_filter($list, fn ($v) => is_numeric(trim((string) $v)))));
    }
}
