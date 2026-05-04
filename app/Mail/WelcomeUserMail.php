<?php
// app/Mail/WelcomeUserMail.php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeUserMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public array $featuredProducts;

    public function __construct(User $user, array $featuredProducts = [])
    {
        $this->user             = $user;
        $this->featuredProducts = $featuredProducts;
        $this->queue            = 'emails';
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