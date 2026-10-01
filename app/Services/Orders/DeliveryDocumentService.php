<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderExport;
use App\Models\SellerOrder;
use App\Support\SellerPickup;
use App\Support\TunisianPhone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Delivery documents for the courier, and the internal admin summary.
 *
 * One order = one buyer, but every seller sub-order is a separate pickup, so
 * the courier gets ONE SLIP PER ACTIVE SUB-ORDER. Money on the slips adds up
 * to exactly what the buyer owes:
 *
 *   slip total = sub-order subtotal − seller coupon + shipping share
 *   shipping   = the order's shipping_fee, all of it on the FIRST active
 *                sub-order (lowest id), 0 on the others — the customer pays
 *                shipping once per order (same rule as
 *                FinancialSnapshotService::deliveryFeeFor, but skipping
 *                cancelled sub-orders so the fee is never lost)
 *   COD        = slip total, or 0 when the order is prepaid (wallet / card /
 *                D17 confirmed) — Σ COD over the slips = order total.
 *
 * Slips never contain internal data (commission, payouts, plan, admin note);
 * those only appear in summary().
 */
class DeliveryDocumentService
{
    /** Sub-orders that will not be shipped. */
    const INACTIVE_STATUSES = ['cancelled', 'refunded'];

    /** Eager loads for every document — no query per sub-order or item. */
    public function query()
    {
        return Order::with([
            'user:id,name,email',
            'sellerOrders' => fn($q) => $q->orderBy('id'),
            'sellerOrders.items' => fn($q) => $q->orderBy('id'),
            'sellerOrders.seller:id,name,email',
            'sellerOrders.seller.sellerApplication',
        ]);
    }

    /** @return Collection<int, SellerOrder> active sub-orders, lowest id first */
    public function activeSellerOrders(Order $order): Collection
    {
        return $order->sellerOrders
            ->reject(fn($so) => in_array($so->status, self::INACTIVE_STATUSES, true))
            ->sortBy('id')
            ->values();
    }

    /** Nothing to collect at the door: paid online / by wallet. */
    public function isPrepaid(Order $order): bool
    {
        return ($order->payment_method ?? 'cod') !== 'cod' || $order->payment_status === 'paid';
    }

    /**
     * Money for one sub-order as printed on its slip.
     *
     * @return array{subtotal:float, discount:float, shipping:float, total:float, cod:float}
     */
    public function money(Order $order, SellerOrder $sellerOrder): array
    {
        $first    = $this->activeSellerOrders($order)->first();
        $subtotal = round((float) $sellerOrder->subtotal, 3);
        $discount = round((float) ($sellerOrder->discount_amount ?? 0), 3);
        $shipping = $first && $first->id === $sellerOrder->id ? round((float) ($order->shipping_fee ?? 0), 3) : 0.0;
        $total    = round($subtotal - $discount + $shipping, 3);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $shipping,
            'total'    => $total,
            'cod'      => $this->isPrepaid($order) ? 0.0 : $total,
        ];
    }

    /** "ORD-AB12CD34-S57": what the courier scans. Stable for a sub-order. */
    public function reference(Order $order, SellerOrder $sellerOrder): string
    {
        return ($order->order_number ?: 'ORD-' . $order->id) . '-S' . $sellerOrder->id;
    }

    // ── Readiness ─────────────────────────────────────────────────────────────

    /** Problems that block printing the buyer's half of every slip. */
    public function orderIssues(Order $order): array
    {
        $issues = [];
        if (!$order->hasDeliverableAddress()) {
            $issues[] = 'Shipping address not recorded (legacy order) — call the customer and add it before shipping.';
        }
        if (($order->payment_method ?? 'cod') !== 'cod' && $order->payment_status !== 'paid') {
            $issues[] = 'Online payment (' . strtoupper($order->payment_method) . ') not confirmed yet.';
        }
        if ($this->activeSellerOrders($order)->isEmpty()) {
            $issues[] = 'No active seller sub-order to ship.';
        }
        return $issues;
    }

    public function pickupIssues(array $pickup): array
    {
        return $pickup['complete'] ? [] : ['Pickup address incomplete for ' . ($pickup['shop_name'] ?? 'seller') . ': missing ' . implode(', ', $pickup['missing']) . '.'];
    }

    /**
     * Everything that blocks exporting slips for the order (all sub-orders, or
     * just $only). Empty = ready.
     */
    public function exportIssues(Order $order, ?SellerOrder $only = null): array
    {
        $issues = $this->orderIssues($order);
        $targets = $only ? collect([$only]) : $this->activeSellerOrders($order);
        foreach ($targets as $so) {
            $issues = array_merge($issues, $this->pickupIssues(SellerPickup::for($so->seller, $so->seller_id)));
        }
        return array_values(array_unique($issues));
    }

    // ── Document data ─────────────────────────────────────────────────────────

    public function recipient(Order $order): array
    {
        return [
            'name'            => $order->recipientName(),
            'phone'           => $order->phone ? TunisianPhone::format($order->phone) : null,
            'phone_secondary' => $order->phone_secondary ? TunisianPhone::format($order->phone_secondary) : null,
            'address'         => $order->address,
            'delegation'      => $order->delegation,
            'postal_code'     => $order->postal_code,
            'wilaya'          => $order->wilaya,
            'notes'           => $order->notes,
            'legacy'          => !$order->hasStructuredAddress(),
        ];
    }

    /** Data for one slip — deliberately no internal financial fields. */
    public function slip(Order $order, SellerOrder $sellerOrder): array
    {
        $pickup = SellerPickup::for($sellerOrder->seller, $sellerOrder->seller_id);
        if ($pickup['phone']) {
            $pickup['phone'] = TunisianPhone::format($pickup['phone']);
        }

        return [
            'order_number' => $order->order_number,
            'reference'    => $this->reference($order, $sellerOrder),
            'order_date'   => $order->created_at,
            'pickup'       => $pickup,
            'recipient'    => $this->recipient($order),
            'items'        => $sellerOrder->items->map(fn($i) => [
                'name'       => $i->product_name ?? 'Product #' . $i->product_id,
                'variant'    => $i->variant_label,
                'quantity'   => (int) $i->quantity,
                'unit_price' => round((float) $i->unit_price, 3),
                'total'      => round((float) $i->total, 3),
                'in_bundle'  => (float) $i->unit_price == 0.0 && Str::contains((string) $i->product_name, '(Bundle:'),
            ])->all(),
            'money'          => $this->money($order, $sellerOrder),
            'coupon_code'    => $sellerOrder->coupon_code,
            'payment_method' => $order->payment_method ?? 'cod',
            'payment_status' => $order->payment_status,
            'prepaid'        => $this->isPrepaid($order),
            'packages'       => 1,
        ];
    }

    /** @return array[] one entry per active sub-order */
    public function slipsFor(Order $order): array
    {
        return $this->activeSellerOrders($order)->map(fn($so) => $this->slip($order, $so))->all();
    }

    // ── PDF ───────────────────────────────────────────────────────────────────

    /** @param array[] $slips */
    public function slipsPdf(array $slips, string $title): string
    {
        return $this->render('pdf.delivery-slips', ['slips' => $slips], $title);
    }

    public function summaryPdf(Order $order): string
    {
        $order->loadMissing('exports.exporter:id,name');

        $subOrders = $order->sellerOrders->map(function (SellerOrder $so) use ($order) {
            $active = !in_array($so->status, self::INACTIVE_STATUSES, true);
            return [
                'model'     => $so,
                'active'    => $active,
                'reference' => $this->reference($order, $so),
                'pickup'    => SellerPickup::for($so->seller, $so->seller_id),
                'money'     => $active ? $this->money($order, $so) : null,
                'items'     => $so->items,
            ];
        });

        return $this->render('pdf.order-summary', [
            'order'     => $order,
            'recipient' => $this->recipient($order),
            'subOrders' => $subOrders,
            'money'     => $order->moneySummary(),
            'issues'    => $this->exportIssues($order),
            'prepaid'   => $this->isPrepaid($order),
            'codTotal'  => round($subOrders->sum(fn($s) => $s['money']['cod'] ?? 0), 3),
        ], 'INTERNAL - ' . $order->order_number);
    }

    private function render(string $view, array $data, string $title): string
    {
        $tmp = storage_path('app/mpdf');
        if (!is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'margin_left'      => 10,
            'margin_right'     => 10,
            'margin_top'       => 10,
            'margin_bottom'    => 12,
            'tempDir'          => $tmp,
            'default_font'     => 'dejavusans',
            // Arabic names/addresses: detect the script, switch to a font with
            // Arabic glyphs and apply shaping + right-to-left ordering.
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
            'showWatermarkText' => true, // only the internal summary sets one
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor("CHOOSE'Tounsi");
        $mpdf->SetCreator("CHOOSE'Tounsi admin");

        $mpdf->WriteHTML(view($view, $data + ['logo' => resource_path('pdf/logo.png')])->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    // ── Filenames & audit ─────────────────────────────────────────────────────

    /** "CT-ORD-AB12CD34" — the order number with the platform prefix, once. */
    public function filenameBase(Order $order): string
    {
        $number = $order->order_number ?: 'ORD-' . $order->id;
        return Str::startsWith($number, 'CT-') ? $number : 'CT-' . $number;
    }

    public function slipFilename(Order $order, SellerOrder $sellerOrder): string
    {
        $shop = SellerPickup::for($sellerOrder->seller, $sellerOrder->seller_id)['shop_name'] ?? 'seller-' . $sellerOrder->seller_id;
        $slug = Str::slug(Str::ascii($shop)) ?: 'seller-' . ($sellerOrder->seller_id ?? $sellerOrder->id);
        return $this->filenameBase($order) . '-seller-' . Str::limit($slug, 40, '') . '.pdf';
    }

    public function log(Order $order, string $type, ?int $userId, ?int $sellerOrderId = null): void
    {
        OrderExport::create([
            'order_id'        => $order->id,
            'seller_order_id' => $sellerOrderId,
            'type'            => $type,
            'exported_by'     => $userId,
        ]);
    }
}
