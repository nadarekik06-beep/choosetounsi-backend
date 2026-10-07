<?php

namespace App\Notifications\Growth;

use App\Models\Coupon;
use App\Notifications\Buyer\BuyerNotification;

/**
 * Bell notification to a buyer who is in a targeted coupon's audience
 * (Growth Radar → warm audience), in the buyer's language. Promotions category:
 * the buyer can turn it off, at most one promotion a day (BuyerNotifier).
 * The e-mail, sent only with marketing consent, is App\Mail\Growth\TargetedCouponMail
 * (it carries the one-click unsubscribe link).
 */
class TargetedCouponNotification extends BuyerNotification
{
    public $tries = 3;

    public function __construct(public string $code, public string $discountType, public float $discountValue,
                                public string $productName, public ?string $productSlug, public string $shop,
                                public ?string $expiresAt, public ?int $couponId = null)
    {
        parent::__construct();
    }

    public static function for(Coupon $coupon, ?object $product, string $shop): self
    {
        return new self($coupon->code, $coupon->discount_type, (float) $coupon->discount_value, (string) ($product->name ?? ''),
            $product->slug ?? null, $shop, $coupon->expires_at?->toIso8601String(), $coupon->id);
    }

    public static function discountLabel(string $type, float $value): string
    {
        return $type === 'percentage'
            ? __('messages.discount.percent_off', ['value' => (int) $value])
            : __('messages.discount.amount_off', ['value' => number_format($value, 3)]);
    }

    public function category(): string { return 'promotions'; }

    public function dedupeKey(): ?string
    {
        return $this->couponId ? "coupon:{$this->couponId}:targeted" : null;
    }

    protected function hasMail(): bool { return false; }

    protected function type(): string { return 'targeted_coupon'; }
    protected function icon(): string { return 'ticket'; }
    protected function action(): string { return 'coupon'; }
    protected function link(): ?string { return $this->productSlug ? "/products/{$this->productSlug}" : '/'; }

    protected function title(): string
    {
        return __('growth.coupon.title', [
            'discount' => self::discountLabel($this->discountType, $this->discountValue),
            'product'  => $this->productName,
        ]);
    }

    protected function body(): string
    {
        return __('growth.coupon.body', ['code' => $this->code, 'shop' => $this->shop]);
    }

    protected function data(): array
    {
        return [
            'coupon_id'   => $this->couponId,
            'coupon_code' => $this->code,
            'expires_at'  => $this->expiresAt,
        ];
    }
}
