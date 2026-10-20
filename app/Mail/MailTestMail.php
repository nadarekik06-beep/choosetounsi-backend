<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Plain test e-mail for php artisan mail:test: goes through the "emails"
 * queue and the configured mailer, with the global From and Reply-To.
 */
class MailTestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $sentAt)
    {
        $this->queue = 'emails';
    }

    public function build(): self
    {
        $from    = e(config('mail.from.address'));
        $replyTo = e((string) config('mail.reply_to.address')) ?: '(none)';
        $mailer  = e((string) config('mail.default'));
        $at      = e($this->sentAt);

        return $this->subject("Choose'Tounsi mail test {$this->sentAt}")
            ->html("<p>Test e-mail from Choose'Tounsi.</p>"
                . "<p>Queued at {$at} on the <b>emails</b> queue, mailer <b>{$mailer}</b>.<br>"
                . "From: {$from}<br>Reply-To: {$replyTo}</p>"
                . "<p>Reply to this message to check that the reply reaches the Reply-To inbox.</p>");
    }
}
