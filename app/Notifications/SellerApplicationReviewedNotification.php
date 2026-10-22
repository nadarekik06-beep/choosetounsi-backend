<?php

namespace App\Notifications;

use App\Notifications\Buyer\BuyerNotification;

/**
 * The user's seller application was reviewed (account category, always on).
 *   approved  shown in the storefront bell, where the applicant is when it
 *             arrives: the storefront re-reads the user from /api/auth/user on
 *             it and its link opens /seller at once (no re-login); the welcome
 *             e-mail is SellerApplicationApprovedMail
 *   rejected  shown in the storefront bell, with an e-mail and the reason
 */
class SellerApplicationReviewedNotification extends BuyerNotification
{
    /**
     * @param string      $action       'approved' | 'rejected'
     * @param string      $businessName
     * @param string|null $reason       rejection reason (optional)
     */
    public function __construct(public string $action, public string $businessName, public ?string $reason = null)
    {
        parent::__construct();
    }

    public function category(): string { return 'account'; }

    protected function hasMail(): bool { return $this->action === 'rejected'; }

    protected function type(): string { return 'seller_application_reviewed'; }
    protected function icon(): string { return $this->action === 'approved' ? 'check-circle' : 'x-circle'; }
    protected function action(): string { return $this->action === 'approved' ? 'approved' : 'rejected'; }
    protected function link(): ?string { return $this->action === 'approved' ? '/seller' : '/become-a-vendor'; }

    protected function title(): string
    {
        return __("buyer_notifications.account.application_{$this->action}.title");
    }

    protected function body(): string
    {
        $key = $this->action === 'rejected' && filled($this->reason) ? 'reason' : 'body';
        return __("buyer_notifications.account.application_{$this->action}.{$key}", [
            'name'   => $this->businessName,
            'reason' => (string) $this->reason,
        ]);
    }

    protected function mailSubject(): string
    {
        return __("buyer_notifications.account.application_{$this->action}.subject", ['name' => $this->businessName]);
    }

    protected function mailButton(): string
    {
        return __("buyer_notifications.account.application_{$this->action}.button");
    }

    protected function data(): array
    {
        return array_filter(['business_name' => $this->businessName, 'reason' => $this->reason]);
    }
}
