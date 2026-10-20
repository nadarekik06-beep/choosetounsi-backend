<?php

namespace App\Console\Commands;

use App\Mail\MailTestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTest extends Command
{
    protected $signature = 'mail:test {to : recipient address}';

    protected $description = 'Queue a test e-mail on the "emails" queue (sent by the queue worker)';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("Not an e-mail address: {$to}");
            return self::FAILURE;
        }

        $at = now()->toIso8601String();
        Mail::to($to)->queue(new MailTestMail($at));

        $this->info("Queued on \"emails\" for {$to} at {$at}");
        $this->line('Mailer: ' . config('mail.default') . ' ' . config('mail.mailers.smtp.host') . ':' . config('mail.mailers.smtp.port'));
        $this->line('From: ' . config('mail.from.name') . ' <' . config('mail.from.address') . '>');
        $this->line('Reply-To: ' . (config('mail.reply_to.address') ?: '(none)'));
        return self::SUCCESS;
    }
}
