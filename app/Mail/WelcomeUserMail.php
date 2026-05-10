<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// ── NOTE: ShouldQueue intentionally removed ────────────────────────────────
// With QUEUE_CONNECTION=sync and no queue worker running on XAMPP,
// using ShouldQueue + a named queue ('emails') causes the mail to be
// dispatched to a non-existent queue and silently dropped.
// We send synchronously, exactly like VerificationCodeMail.
// If you later add Redis + a queue worker, you can re-add ShouldQueue
// and remove the named queue line.
// ──────────────────────────────────────────────────────────────────────────

class WelcomeUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public array $featuredProducts;

    public function __construct(User $user, array $featuredProducts = [])
    {
        $this->user             = $user;
        $this->featuredProducts = $featuredProducts;
        // $this->queue = 'emails'; ← REMOVED: caused silent drop on sync driver
    }

    public function build(): self
    {
        return $this
            ->subject("Welcome to ChooseTounsi 🇹🇳 — Tunisia's Local Marketplace")
            ->view('emails.welcome.user')
            ->with([
                'user'             => $this->user,
                'featuredProducts' => $this->featuredProducts,
            ]);
    }
}