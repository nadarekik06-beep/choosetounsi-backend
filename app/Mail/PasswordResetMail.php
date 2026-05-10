<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public User   $user;
    public string $token;
    public string $resetUrl;

    public function __construct(User $user, string $token)
    {
        $this->user     = $user;
        $this->token    = $token;

        // Build the full frontend reset URL with token + email as query params.
        // The Next.js page at /auth/reset-password will read these from the URL.
        $frontendUrl    = env('FRONTEND_URL', 'http://localhost:3000');
        $this->resetUrl = $frontendUrl
            . '/auth/reset-password?token='
            . urlencode($token)
            . '&email='
            . urlencode($user->email);
    }

    public function build(): self
    {
        return $this
            ->subject('Reset Your ChooseTounsi Password')
            ->view('emails.auth.password-reset');
    }
}