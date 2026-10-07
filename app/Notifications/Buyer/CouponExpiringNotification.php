<?php

namespace App\Notifications\Buyer;

use App\Models\Coupon;
use App\Notifications\Growth\TargetedCouponNotification;

/** A private coupon the buyer received (Growth Radar) and hasn't used expires soon. */
class CouponExpiringNotification extends BuyerNotification
{
    public function __construct(
        public Coupon $coupon,
        public string $productName,
        public ?string $productSlug,
        public string $shop,
    ) {
        parent::__construct();
    }

    public function category(): string { return 'promotions'; }
    public function dedupeKey(): ?string { return "coupon:{$this->coupon->id}:expiring"; }

    protected function type(): string { return 'coupon_expiring'; }
    protected function icon(): string { return 'ticket'; }
    protected function action(): string { return 'coupon'; }
    protected function link(): ?string { return $this->productSlug ? "/products/{$this->productSlug}" : '/'; }

    protected function title(): string { return __('buyer_notifications.promo.coupon_expiring.title', $this->params()); }
    protected function body(): string { return __('buyer_notifications.promo.coupon_expiring.body', $this->params()); }
    protected function mailSubject(): string { return __('buyer_notifications.promo.coupon_expiring.subject', $this->params()); }
    protected function mailButton(): string { return __('buyer_notifications.promo.coupon_expiring.button'); }

    protected function data(): array
    {
        return [
            'coupon_id'   => $this->coupon->id,
            'coupon_code' => $this->coupon->code,
            'expires_at'  => $this->coupon->expires_at?->toIso8601String(),
        ];
    }

    private function params(): array
    {
        return [
            'code'     => $this->coupon->code,
            'discount' => TargetedCouponNotification::discountLabel($this->coupon->discount_type, (float) $this->coupon->discount_value),
            'product'  => $this->productName,
            'shop'     => $this->shop,
            'date'     => $this->coupon->expires_at
                ? $this->coupon->expires_at->copy()->timezone(config('notifications.timezone'))->locale('fr')->translatedFormat('j F à H\hi')
                : '',
        ];
    }
}
