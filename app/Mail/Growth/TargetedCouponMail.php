<?php

namespace App\Mail\Growth;

use App\Mail\Marketing\MarketingMail;
use App\Models\User;
use App\Notifications\Growth\TargetedCouponNotification;
use Carbon\CarbonImmutable;

/** "A private coupon for <product>" — only to buyers who opted in to marketing e-mails. */
class TargetedCouponMail extends MarketingMail
{
    public function __construct(User $user, public array $coupon, string $unsubscribeUrl)
    {
        parent::__construct($user, $unsubscribeUrl);
    }

    public function build(): self
    {
        $c = $this->coupon;
        $c['discount'] = TargetedCouponNotification::discountLabel($c['discount_type'], $c['discount_value']);
        $c['expires']  = $c['expires_at'] ? CarbonImmutable::parse($c['expires_at'])->locale(app()->getLocale())->translatedFormat('j F') : null;

        return $this->withUnsubscribeHeaders()
            ->subject(__('growth.coupon.subject', ['product' => $c['product']]))
            ->view('emails.growth.coupon', ['c' => $c, 'unsubscribeUrl' => $this->unsubscribeUrl]);
    }
}
