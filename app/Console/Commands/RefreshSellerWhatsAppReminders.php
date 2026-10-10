<?php

namespace App\Console\Commands;

use App\Services\Orders\WhatsApp\SellerReminderService;
use Illuminate\Console\Command;

/**
 * Seller WhatsApp reminders (admin panel → WhatsApp reminders): pending
 * reminders whose time has come become due, and reminders of parcels that
 * moved on (prepared, cancelled, shipped…) are cancelled. Every 5 minutes.
 */
class RefreshSellerWhatsAppReminders extends Command
{
    protected $signature   = 'orders:whatsapp-reminders
                              {--due-now : Testing: make every pending reminder due right away}';
    protected $description = 'Switch due seller WhatsApp reminders to "due" and cancel those no longer needed';

    public function handle(SellerReminderService $reminders): int
    {
        if ($this->option('due-now')) {
            $moved = \App\Models\SellerOrderReminder::where('status', \App\Models\SellerOrderReminder::PENDING)
                ->update(['due_at' => now()->subMinute()]);
            $this->warn("Testing: {$moved} pending reminder(s) moved to now.");
        }

        $result = $reminders->refresh();
        $this->info("Due: {$result['due']} · cancelled: {$result['cancelled']} · overdue parcels: {$result['overdue']}");
        return self::SUCCESS;
    }
}
