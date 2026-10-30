<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Support\GuestCheckout;
use Illuminate\Console\Command;

/**
 * Turn guest checkout on / off instantly (no deploy, no config:cache):
 *
 *   php artisan guest-checkout status
 *   php artisan guest-checkout off     → login required again, right away
 *   php artisan guest-checkout on
 *   php artisan guest-checkout reset   → follow GUEST_CHECKOUT_ENABLED again
 */
class GuestCheckoutSwitch extends Command
{
    protected $signature = 'guest-checkout {action=status : on | off | status | reset}';

    protected $description = 'Turn guest checkout (order without an account) on or off instantly';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));

        switch ($action) {
            case 'on':
            case 'off':
                PlatformSetting::setValue(GuestCheckout::SETTING_KEY, ['enabled' => $action === 'on']);
                break;
            case 'reset':
                PlatformSetting::where('key', GuestCheckout::SETTING_KEY)->delete();
                PlatformSetting::flushCache();
                break;
            case 'status':
                break;
            default:
                $this->error('Use: on | off | status | reset');
                return self::FAILURE;
        }

        PlatformSetting::flushCache();
        $override = GuestCheckout::override();
        $this->line('Guest checkout: ' . (GuestCheckout::enabled() ? '<info>ON</info>' : '<comment>OFF</comment>'));
        $this->line('  .env GUEST_CHECKOUT_ENABLED: ' . (config('guest_checkout.enabled') ? 'true' : 'false'));
        $this->line('  override (this command):     ' . ($override === null ? 'none' : ($override ? 'on' : 'off')));

        return self::SUCCESS;
    }
}
