<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Admin: a seller edited a live product. One notification per save, grouping
 * every changed field ("Seller X updated Product Y: price, stock, 2 images").
 */
class ProductChangedNotification extends Notification
{
    private const REASON_LABELS = [
        'name_changed'     => 'name changed',
        'category_changed' => 'category changed',
        'images_changed'   => 'images changed',
        'price_jump'       => 'price moved more than 50%',
    ];

    public function __construct(
        private int $changeSetId,
        private int $productId,
        private string $productName,
        private string $sellerName,
        private string $summary,
        private bool $sensitive,
        private array $reasons = [],
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $body = "{$this->sellerName} updated \"{$this->productName}\": {$this->summary}.";
        if ($this->sensitive) {
            $labels = array_map(fn($r) => self::REASON_LABELS[$r] ?? $r, $this->reasons);
            $body .= ' Check: ' . implode(', ', $labels) . '.';
        }

        return [
            'type'          => 'product_changed',
            'title'         => $this->sensitive ? '⚠ Sensitive product change' : 'Product updated by seller',
            'body'          => $body,
            'icon'          => $this->sensitive ? 'alert-triangle' : 'package-check',
            'link'          => '/product-changes?set=' . $this->changeSetId,
            'product_id'    => $this->productId,
            'change_set_id' => $this->changeSetId,
            'sensitive'     => $this->sensitive,
            'reasons'       => $this->reasons,
        ];
    }
}
