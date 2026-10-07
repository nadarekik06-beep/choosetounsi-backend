<?php

namespace App\Notifications\Orders;

use App\Models\SellerOrder;
use App\Services\Orders\DeliveryDocumentService;
use App\Support\SellerPickup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Base of the seller sub-order notifications (e-mail + dashboard bell).
 *
 * Queued, one job per channel: if the e-mail keeps failing, the bell entry is
 * still saved. Sent in the seller's language (User::preferredLocale()).
 *
 * Privacy: the seller sees the buyer's first name and wilaya only — never
 * their phone or street address (the courier handles delivery).
 *
 * Dispatch through App\Services\Orders\SellerOrderNotifier, never directly:
 * it guarantees one send per sub-order and event, after the commit.
 */
abstract class SellerOrderNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $tries = 5;

    private ?array $summary = null;

    public function __construct(public SellerOrder $sellerOrder)
    {
        $this->onQueue(config('seller_notifications.queue'));
        $this->afterCommit();   // also queued only after any open transaction commits
    }

    /** placed | confirmed | cancelled | pickup_reminder — also the lang key. */
    abstract public function event(): string;

    /** Bell row look (see NotificationBell.tsx). */
    abstract protected function icon(): string;
    abstract protected function action(): string;

    /** Retry a failing SMTP send after 1 min, 5 min, 15 min, then 1 h. */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function via($notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function failed(\Throwable $e): void
    {
        Log::error(sprintf(
            '[SellerOrderNotification] %s for seller_order %d failed after %d tries: %s',
            $this->event(), $this->sellerOrder->id, $this->tries, $e->getMessage()
        ));
    }

    // ── Channels ──────────────────────────────────────────────────────────────

    public function toDatabase($notifiable): array
    {
        $s = $this->summary();

        return [
            'type'            => 'seller_order',
            'event'           => $this->event(),
            'action'          => $this->action(),
            'icon'            => $this->icon(),
            'title'           => __("order_notifications.{$this->event()}.title", ['ref' => $s['reference']]),
            'body'            => trans_choice("order_notifications.{$this->event()}.body", $s['item_count'], [
                'count' => $s['item_count'],
            ]),
            'link'            => $s['dashboard_path'],
            'seller_order_id' => $this->sellerOrder->id,
            'order_id'        => $this->sellerOrder->order_id,
            'reference'       => $s['reference'],
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $s     = $this->summary();
        $event = $this->event();

        $subject = trans_choice("order_notifications.{$event}.subject", $s['item_count'], [
            'ref'   => $s['reference'],
            'count' => $s['item_count'],
        ]);

        $data = [
            'event'     => $event,
            'subject'   => $subject,
            'sellerName'=> $this->sellerFirstName($notifiable),
            's'         => $this->mailSummary($s),
            'pickup'    => $this->withPickup() ? $this->pickup() : null,
            'logoUrl'   => config('seller_notifications.logo_url'),
            'rtl'       => app()->getLocale() === 'ar',
        ];

        return (new MailMessage)
            ->subject($subject)
            ->view([$this->mailView(), $this->mailView() . '-text'], $data);
    }

    // ── Content ───────────────────────────────────────────────────────────────

    /** Blade view of the e-mail (HTML); the plain-text part is the same name + "-text". */
    protected function mailView(): string
    {
        return 'emails.seller-orders.notification';
    }

    /**
     * What the e-mail view receives from summary(). Events that must not show
     * money (new order, cancelled) narrow it here, so their views can't print it.
     */
    protected function mailSummary(array $summary): array
    {
        return $summary;
    }

    /** Whether the e-mail shows the pickup address (and packing tips). */
    protected function withPickup(): bool
    {
        return false;
    }

    /** Everything the e-mail and the bell show, computed from fresh data. */
    protected function summary(): array
    {
        if ($this->summary !== null) {
            return $this->summary;
        }

        $so    = $this->sellerOrder->loadMissing([
            'order.user:id,name',
            'items.product' => fn($q) => $q->withTrashed()->with('images'),
            'items.variant',
        ]);
        $order = $so->order;

        $items = $so->items->map(fn($item) => [
            'name'       => $item->product_name,
            'variant'    => $item->displayVariantLabel(),
            'qty'        => (int) $item->quantity,
            // Pack follower rows carry 0: the pack price sits on its first row.
            'unit_price' => (float) $item->unit_price > 0 ? $this->money((float) $item->unit_price) : null,
            'total'      => (float) $item->total > 0 ? $this->money((float) $item->total) : null,
            'image'      => $this->imageUrl($item),
        ])->values()->all();

        $itemsTotal = (float) $so->subtotal;
        $discount   = (float) ($so->discount_amount ?? 0);
        $commission = (float) ($so->commission_amount ?? $so->items->sum('commission_amount'));
        $shipping   = (float) ($so->seller_shipping_charge ?? 0);
        $net        = $so->seller_net_amount !== null
            ? (float) $so->seller_net_amount
            : (float) $so->items->sum('seller_amount') - $shipping;

        $path = '/seller/orders?order=' . $so->id;

        return $this->summary = [
            'reference'      => app(DeliveryDocumentService::class)->reference($order, $so),
            'order_date'     => $order->created_at
                ->copy()->timezone(config('seller_notifications.timezone'))
                ->locale(app()->getLocale())->translatedFormat('j F Y, H:i'),
            'buyer'          => trim(implode(' · ', array_filter([$this->buyerFirstName($order), $order->wilaya]))),
            'items'          => $items,
            'item_count'     => (int) $so->items->sum('quantity'),
            'items_total'    => $this->money($itemsTotal),
            'discount'       => $discount > 0 ? $this->money($discount) : null,
            'commission'     => $this->money($commission),
            'shipping'       => $shipping > 0 ? $this->money($shipping) : null,
            'net_label'      => $this->money($net),
            'dashboard_path' => $path,
            'dashboard_url'  => rtrim(config('app.frontend_url'), '/') . $path,
        ];
    }

    /** Pickup point on file, with the missing fields translated. */
    protected function pickup(): array
    {
        $pickup = SellerPickup::for($this->sellerOrder->seller, $this->sellerOrder->seller_id);

        $missing = [];
        foreach (SellerPickup::REQUIRED as $key => $label) {
            if (in_array($label, $pickup['missing'], true)) $missing[] = $key;
        }
        if (in_array('valid phone', $pickup['missing'], true))       $missing[] = 'phone';
        if (in_array('valid postal code', $pickup['missing'], true)) $missing[] = 'postal_code';

        return [
            'shop'         => $pickup['shop_name'],
            'address'      => SellerPickup::formatAddress($pickup),
            'phone'        => $pickup['phone'],
            'complete'     => $pickup['complete'],
            'missing'      => array_map(fn($k) => __("order_notifications.pickup_fields.{$k}"), array_unique($missing)),
            'settings_url' => rtrim(config('app.frontend_url'), '/') . '/seller/settings#pickup',
        ];
    }

    /**
     * Absolute product photo URL for the e-mail, or null (no broken image:
     * the name is always shown). Relative URLs can't load in a mail client.
     */
    private function imageUrl($item): ?string
    {
        try {
            $url = $item->displayImageUrl();   // as bought, never another variant's image
        } catch (\Throwable $e) {
            return null;
        }
        return $url && preg_match('#^https?://#i', $url) ? $url : null;
    }

    private function buyerFirstName($order): ?string
    {
        $name = trim((string) ($order->recipient_name ?: $order->user?->name));
        return $name === '' ? null : preg_split('/\s+/u', $name)[0];
    }

    private function sellerFirstName($seller): string
    {
        $name = trim((string) ($seller->sellerApplication?->full_name ?: $seller->name));
        return $name === '' ? '' : preg_split('/\s+/u', $name)[0];
    }

    private function money(float $amount): string
    {
        return __('order_notifications.money', ['amount' => number_format($amount, 3, '.', ' ')]);
    }
}
