<?php

namespace App\Mail\Marketing;

use App\Models\User;

/** Weekly "Picked for you": organic recommendations + clearly labelled sponsored products. */
class PickedForYouMail extends MarketingMail
{
    /** @param array<int, array> $items see AdEmailService::item() */
    public function __construct(User $user, public array $items, string $unsubscribeUrl)
    {
        parent::__construct($user, $unsubscribeUrl);
    }

    public function build(): self
    {
        return $this->withUnsubscribeHeaders()
            ->subject(__('emails.marketing.digest.subject'))
            ->view('emails.marketing.digest', [
                'user'           => $this->user,
                'items'          => $this->items,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ]);
    }
}
