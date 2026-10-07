<?php

namespace App\Notifications\Buyer;

/**
 * Account & security (always on, bell + e-mail):
 *   password_changed  profile change or reset link
 *   email_changed     also e-mailed to the previous address
 */
class AccountSecurityNotification extends BuyerNotification
{
    public function __construct(public string $event, public array $params = [])
    {
        parent::__construct();
    }

    public function category(): string { return 'account'; }

    protected function type(): string { return $this->event; }
    protected function icon(): string { return 'shield'; }
    protected function action(): string { return 'security'; }
    protected function link(): ?string { return '/profile?tab=settings'; }

    protected function title(): string { return __("buyer_notifications.account.{$this->event}.title", $this->params); }
    protected function body(): string { return __("buyer_notifications.account.{$this->event}.body", $this->params); }
    protected function mailSubject(): string { return __("buyer_notifications.account.{$this->event}.subject", $this->params); }
    protected function mailButton(): string { return __("buyer_notifications.account.{$this->event}.button"); }
    protected function mailLines(): array { return [__("buyer_notifications.account.{$this->event}.line", $this->params)]; }

    protected function data(): array
    {
        return ['at' => now()->toIso8601String()];
    }
}
