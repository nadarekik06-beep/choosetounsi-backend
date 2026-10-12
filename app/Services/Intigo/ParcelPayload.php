<?php

namespace App\Services\Intigo;

use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\Delivery\DeliverySettings;
use App\Services\Orders\DeliveryDocumentService;
use App\Support\Millimes;
use App\Support\ParcelSize;
use App\Support\TunisianPhone;

/**
 * The POST /parcels/by-name body for one seller_order (= one parcel).
 *
 *   price            this parcel's cash-on-delivery amount, frozen at checkout:
 *                    the seller's items after coupon / flash sale / discount +
 *                    this parcel's client delivery fee (exactly what the client
 *                    was shown for that shop), 3 decimals. Never 0 (prepaid).
 *   additional_info  our order reference only: never the seller's name, phone
 *                    or address (the pickup address lives at Intigo).
 *   cid              "CT-SO-{seller_order_id}": finds the parcel again after a
 *                    lost answer. Never is_exchange (it adds fees).
 */
class ParcelPayload
{
    public function __construct(
        private DeliverySettings        $settings,
        private DeliveryDocumentService $documents,
    ) {}

    public static function cid(int $sellerOrderId): string
    {
        return 'CT-SO-' . $sellerOrderId;
    }

    /**
     * @return array{payload: ?array, problems: string[]} problems = why it can't be sent
     */
    public function build(SellerOrder $so): array
    {
        $so->loadMissing(['order', 'seller', 'items']);
        /** @var Order $order */
        $order    = $so->order;
        $problems = [];

        $pickup = self::pickupIndex($so);
        if ($pickup === null) {
            $problems[] = 'seller has no Intigo pickup address';
        }

        if (($order->payment_method ?? 'cod') !== 'cod') {
            $problems[] = 'not a cash-on-delivery order (Intigo parcels are COD only)';
        }
        $priceM = self::codMillimes($so);
        if ($priceM <= 0) {
            $problems[] = 'cash-on-delivery amount is ' . Millimes::toDecimal($priceM) . ' TND (nothing to collect: a 100% discount?)';
        }

        $phone1 = TunisianPhone::normalize((string) $order->phone);
        if (!preg_match('/^\d{8}$/', (string) $phone1)) {
            $problems[] = 'client phone is not 8 digits';
        }
        $phone2 = $order->phone_secondary ? TunisianPhone::normalize((string) $order->phone_secondary) : null;

        $recipient = trim((string) ($order->recipient_name ?: $order->user?->name));
        $street    = trim((string) $order->address);
        $city      = $order->city_name ?: (IntigoRegions::cityNameForWilaya($order->wilaya) ?? $order->wilaya);
        $district  = $order->district_name ?: $order->delegation;

        if ($recipient === '')  $problems[] = 'client name missing';
        if ($street === '')     $problems[] = 'street address missing';
        if (!$city)             $problems[] = 'governorate missing';

        if ($problems) {
            return ['payload' => null, 'problems' => $problems];
        }

        // Landmark / delivery notes help the driver find the client
        $destination = $street . ($order->notes ? ' — ' . trim($order->notes) : '');

        $payload = [
            'recipient_name'      => mb_substr($recipient, 0, 255),
            'phone1'              => $phone1,
            'destination_address' => mb_substr($destination, 0, 500),
            'city_name'           => $city,
            'district_name'       => $district ?: $city,   // tolerant at Intigo: falls back to the main town
            'price'               => (float) Millimes::toDecimal($priceM),
            'package_size'        => ParcelSize::packageSize($so->parcel_size),
            'description'         => mb_substr($this->description($so), 0, 500),
            'additional_info'     => mb_substr($this->documents->reference($order, $so), 0, 500),
            'can_open'            => $this->settings->canOpen(),
            'pickup_index'        => $pickup,
            'cid'                 => self::cid($so->id),
        ];
        if ($phone2 && preg_match('/^\d{8}$/', $phone2) && $phone2 !== $phone1) {
            $payload['phone2'] = mb_substr($phone2, 0, 20);
        }

        return ['payload' => $payload, 'problems' => []];
    }

    /** The seller's Intigo pickup address index (the platform's own: INTIGO_PLATFORM_PICKUP_INDEX). */
    public static function pickupIndex(SellerOrder $so): ?int
    {
        if ($so->isPlatformParcel()) {
            $index = config('intigo.platform_pickup_index');
            return is_numeric($index) ? (int) $index : null;
        }
        $index = $so->seller?->intigo_pickup_index;
        return $index !== null ? (int) $index : null;
    }

    /**
     * Cash the driver collects for this parcel: the checkout snapshot
     * (cod_amount); legacy parcels without it: items − coupon + delivery fee.
     */
    public static function codMillimes(SellerOrder $so): int
    {
        $cod = $so->getAttribute('cod_amount');
        if ($cod !== null) {
            return Millimes::of($cod);
        }
        return Millimes::of($so->subtotal) - Millimes::of($so->discount_amount) + Millimes::of($so->getAttribute('delivery_fee'));
    }

    /** "2 × Robe kabyle, 1 × Foulard" (pack follower lines included: they are in the box). */
    private function description(SellerOrder $so): string
    {
        $parts = [];
        foreach ($so->items as $item) {
            $qty = (int) $item->quantity;
            if ($qty <= 0) continue;
            $name = trim((string) ($item->product_name ?: 'Article'));
            $parts[] = "{$qty} × {$name}";
        }
        return $parts ? implode(', ', $parts) : 'Commande ' . $so->id;
    }
}
