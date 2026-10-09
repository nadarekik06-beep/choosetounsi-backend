<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base of the grouped stock alerts (bell + optional e-mail), in the seller's language.
 *
 * $items: [{product_id, variant_id, name, variant_label, stock, threshold}], one row
 * per product/variant that crossed (StockAlertService::flush). One item reads
 * "Trendy Scarf — Noir: 1 left"; several are listed in the body.
 */
abstract class StockAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    /** Items named in the bell body; the rest is "+N". */
    private const BODY_ITEMS = 4;

    public function __construct(public array $items, public bool $withEmail = false)
    {
        $this->onQueue(config('seller_notifications.queue'));
    }

    /** low_stock | out_of_stock — also the lang key under seller.notif. */
    abstract protected function kind(): string;

    abstract protected function action(): string;

    public function via($notifiable): array
    {
        return $this->withEmail && filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'       => $this->kind(),
            'action'     => $this->action(),
            'icon'       => 'package-x',
            'title'      => $this->title(),
            'body'       => $this->body(),
            'link'       => $this->path(),
            'product_id' => count($this->productIds()) === 1 ? $this->items[0]['product_id'] : null,
            'variant_id' => count($this->items) === 1 ? $this->items[0]['variant_id'] : null,
            'items'      => $this->items,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $data = [
            'subject' => $this->title(),
            'title'   => $this->title(),
            'intro'   => __("seller.notif.{$this->kind()}.mail_intro"),
            'items'   => array_map(fn($i) => ['name' => $this->itemName($i), 'line' => $this->itemLine($i)], $this->items),
            'cta'     => __('seller.notif.stock_mail.cta'),
            'url'     => rtrim(config('app.frontend_url'), '/') . $this->path(),
            'footer'  => __('seller.notif.stock_mail.footer'),
            'rtl'     => app()->getLocale() === 'ar',
            'logoUrl' => config('seller_notifications.logo_url'),
        ];
        return (new MailMessage)->subject($data['subject'])
            ->view(['emails.stock.alert', 'emails.stock.alert-text'], $data);
    }

    // ── Content ───────────────────────────────────────────────────────────────

    private function title(): string
    {
        $k = "seller.notif.{$this->kind()}";
        return count($this->items) === 1
            ? __("$k.title", ['name' => $this->itemName($this->items[0])])
            : __("$k.title_many", ['count' => count($this->items)]);
    }

    private function body(): string
    {
        if (count($this->items) === 1) {
            return $this->itemLine($this->items[0]);
        }
        $shown = array_slice($this->items, 0, self::BODY_ITEMS);
        $parts = array_map(fn($i) => $this->kind() === 'low_stock'
            ? __('seller.notif.stock_item', ['name' => $this->itemName($i), 'count' => $i['stock']])
            : $this->itemName($i), $shown);
        $more  = count($this->items) - count($shown);
        return implode(' · ', $parts) . ($more > 0 ? ' · ' . __('seller.notif.stock_more', ['count' => $more]) : '');
    }

    private function itemName(array $i): string
    {
        return $i['variant_label'] ? "{$i['name']} — {$i['variant_label']}" : $i['name'];
    }

    private function itemLine(array $i): string
    {
        return $this->kind() === 'low_stock'
            ? trans_choice('seller.notif.low_stock.body', $i['stock'], ['count' => $i['stock'], 'threshold' => $i['threshold']])
            : __('seller.notif.out_of_stock.body');
    }

    private function productIds(): array
    {
        return array_values(array_unique(array_column($this->items, 'product_id')));
    }

    private function path(): string
    {
        $ids = $this->productIds();
        return count($ids) === 1 ? "/seller/products/{$ids[0]}" : '/seller/products';
    }
}
