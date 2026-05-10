<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Can be a real App\Models\User OR a plain stdClass with ->name and ->email.
     * We accept `object` so we never force a DB row to exist before verification.
     */
    public object $user;
    public string $code;

    public function __construct(object $user, string $code)
    {
        $this->user = $user;
        $this->code = $code;
    }

    public function build(): self
    {
        return $this
            ->subject('Your ChooseTounsi Verification Code')
            ->view('emails.verification.code');
    }
}