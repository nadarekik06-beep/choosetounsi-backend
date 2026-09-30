<?php

namespace App\Mail\Marketing;

use App\Models\User;

/** "Still looking for X?" — one sponsored deal in a subcategory the user keeps browsing. */
class StillLookingMail extends MarketingMail
{
    public function __construct(User $user, public string $category, public array $item, string $unsubscribeUrl)
    {
        parent::__construct($user, $unsubscribeUrl);
    }

    public function build(): self
    {
        return $this->withUnsubscribeHeaders()
            ->subject(__('emails.marketing.interest.subject', ['category' => $this->category]))
            ->view('emails.marketing.interest', [
                'user'           => $this->user,
                'category'       => $this->category,
                'item'           => $this->item,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ]);
    }
}
