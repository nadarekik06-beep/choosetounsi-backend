<?php

namespace App\Mail\Marketing;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for consent-based marketing e-mails: queued, in the user's locale (set by
 * Mail::to($user)), with the unsubscribe link in the footer ($unsubscribeUrl:
 * storefront confirmation page) and the List-Unsubscribe / List-Unsubscribe-Post
 * headers mail clients use (backend one-click POST endpoint).
 */
abstract class MarketingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $unsubscribeUrl) {}

    protected function withUnsubscribeHeaders(): static
    {
        // Header → backend one-click endpoint (POST); the body link → storefront page
        $url = app(\App\Services\Ads\MarketingConsent::class)->oneClickUrl($this->user);
        return $this->withSwiftMessage(function ($message) use ($url) {
            $headers = $message->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', "<{$url}>");
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }
}
