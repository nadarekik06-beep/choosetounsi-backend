<?php

namespace App\Notifications\Buyer;

use App\Models\Product;

/**
 * A favourited product got cheaper or is back in stock (promotions: the buyer
 * can turn it off, at most one promotion a day — BuyerNotifier).
 *   price_drop     base price went down by config('notifications.price_drop_min_percent') %+
 *   back_in_stock  stock went from 0 to available
 */
class FavoriteProductNotification extends BuyerNotification
{
    public function __construct(
        public Product $product,
        public string $event,
        public ?float $oldPrice = null,
        public ?float $newPrice = null,
    ) {
        parent::__construct();
    }

    public function category(): string { return 'promotions'; }

    /** Once per product, event and day (a price yo-yo can't spam). */
    public function dedupeKey(): ?string
    {
        $day = now()->timezone(config('notifications.timezone'))->toDateString();
        return "favorite:{$this->product->id}:{$this->event}:{$day}";
    }

    protected function type(): string { return "favorite_{$this->event}"; }
    protected function icon(): string { return $this->event === 'price_drop' ? 'tag' : 'package-check'; }
    protected function action(): string { return 'coupon'; }
    protected function link(): ?string { return $this->product->slug ? "/products/{$this->product->slug}" : null; }

    protected function title(): string { return __("buyer_notifications.promo.{$this->event}.title", $this->params()); }
    protected function body(): string { return __("buyer_notifications.promo.{$this->event}.body", $this->params()); }
    protected function mailSubject(): string { return __("buyer_notifications.promo.{$this->event}.subject", $this->params()); }
    protected function mailButton(): string { return __("buyer_notifications.promo.{$this->event}.button"); }

    protected function data(): array
    {
        return array_filter([
            'product_id' => $this->product->id,
            'old_price'  => $this->oldPrice,
            'new_price'  => $this->newPrice,
        ], fn ($v) => $v !== null);
    }

    private function params(): array
    {
        return [
            'product' => (string) $this->product->name,
            'old'     => $this->oldPrice !== null ? self::money($this->oldPrice) : '',
            'new'     => $this->newPrice !== null ? self::money($this->newPrice) : '',
        ];
    }
}
