<?php

namespace App\Notifications\Growth;

use App\Models\Coupon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Bell notification to a buyer who is in a targeted coupon's audience
 * (Growth Radar → warm audience), in the buyer's language. The e-mail, sent
 * only with marketing consent, is App\Mail\Growth\TargetedCouponMail.
 */
class TargetedCouponNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public string $code, public string $discountType, public float $discountValue,
                                public string $productName, public ?string $productSlug, public string $shop,
                                public ?string $expiresAt)
    {
        $this->onQueue(config('growth.queue'));
    }

    public static function for(Coupon $coupon, ?object $product, string $shop): self
    {
        return new self($coupon->code, $coupon->discount_type, (float) $coupon->discount_value, (string) ($product->name ?? ''),
            $product->slug ?? null, $shop, $coupon->expires_at?->toIso8601String());
    }

    public static function discountLabel(string $type, float $value): string
    {
        return $type === 'percentage'
            ? __('messages.discount.percent_off', ['value' => (int) $value])
            : __('messages.discount.amount_off', ['value' => number_format($value, 3)]);
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $discount = self::discountLabel($this->discountType, $this->discountValue);
        return [
            'type'        => 'targeted_coupon',
            'action'      => 'coupon',
            'icon'        => 'ticket',
            'title'       => __('growth.coupon.title', ['discount' => $discount, 'product' => $this->productName]),
            'body'        => __('growth.coupon.body', ['code' => $this->code, 'shop' => $this->shop]),
            'link'        => $this->productSlug ? "/products/{$this->productSlug}" : '/',
            'coupon_code' => $this->code,
            'expires_at'  => $this->expiresAt,
        ];
    }
}
