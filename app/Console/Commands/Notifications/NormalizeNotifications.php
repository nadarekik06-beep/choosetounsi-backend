<?php

namespace App\Console\Commands\Notifications;

use App\Models\Admin;
use App\Models\User;
use App\Notifications\Support\Payload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites stored notifications into the payload contract
 * (App\Notifications\Support\Payload): message → body, action_url / url → link,
 * extra keys → data, and fills the audience / category columns.
 *
 * Idempotent: rows already in the contract with both columns set are skipped.
 *   php artisan notifications:normalize --dry-run
 *   php artisan notifications:normalize
 */
class NormalizeNotifications extends Command
{
    protected $signature   = 'notifications:normalize {--dry-run : Count what would change, write nothing} {--chunk=500}';
    protected $description = 'Convert stored notifications to the type/category/audience/title/body/link/icon/data contract';

    public function handle(): int
    {
        $dry     = (bool) $this->option('dry-run');
        $changed = 0;
        $seen    = 0;
        $roles   = [];

        DB::table('notifications')->orderBy('id')->chunk((int) $this->option('chunk'), function ($rows) use ($dry, &$changed, &$seen, &$roles) {
            $userIds = $rows->where('notifiable_type', User::class)->pluck('notifiable_id')->unique()->diff(array_keys($roles));
            if ($userIds->isNotEmpty()) {
                $roles += DB::table('users')->whereIn('id', $userIds)->pluck('role', 'id')->all();
            }

            foreach ($rows as $row) {
                $seen++;
                $raw = json_decode((string) $row->data, true);
                if (!is_array($raw)) continue;

                if (Payload::isNormalized($raw) && $row->audience && $row->category) continue;

                $notifiable = $row->notifiable_type === Admin::class || is_subclass_of($row->notifiable_type, Admin::class)
                    ? new Admin()
                    : (object) ['role' => $roles[$row->notifiable_id] ?? null];

                $payload = Payload::normalize($raw, $row->type, $notifiable);
                $changed++;

                if (!$dry) {
                    DB::table('notifications')->where('id', $row->id)->update([
                        'data'     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'audience' => $payload['audience'],
                        'category' => $payload['category'],
                    ]);
                }
            }
        });

        $this->info(($dry ? '[dry run] ' : '') . "Notifications checked: {$seen}, " . ($dry ? 'to convert' : 'converted') . ": {$changed}");
        return self::SUCCESS;
    }
}
