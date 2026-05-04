<?php
// app/Mail/SellerApplicationSubmittedMail.php

namespace App\Mail;

use App\Models\User;
use App\Models\SellerApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SellerApplicationSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $seller;
    public SellerApplication $application;

    public function __construct(User $seller, SellerApplication $application)
    {
        $this->seller      = $seller;
        $this->application = $application;
        $this->queue       = 'emails';
    }

    public function build(): self
    {
        return $this
            ->subject("Application Received — We're On It! ✅")
            ->view('emails.seller-application.submitted')
            ->with([
                'seller'      => $this->seller,
                'application' => $this->application,
            ]);
    }
}