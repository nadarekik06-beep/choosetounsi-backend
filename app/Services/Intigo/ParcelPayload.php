<?php

namespace App\Services\Intigo;

use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\Delivery\DeliverySettings;
use App\Services\Orders\DeliveryDocumentService;
use App\Support\Millimes;
use App\Services\Sellers\PickupAddressService;
use App\Support\ParcelSize;
use App\Support\SellerPickup;
use App\Models\User;
use App\Support\TestAccounts;
use App\Support\TunisianPhone;
use Illuminate\Support\Facades\DB;

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
        $preview = $this->preview($so);
        return $preview['problems']
            ? ['payload' => null, 'problems' => $preview['problems']]
            : ['payload' => $preview['payload'], 'problems' => []];
    }

    /**
     * The body as it would be sent, even when it can't be (dry run, admin
     * view), and every reason it can't. Reads only our DB: no Intigo call.
     *
     * @return array{payload: array, problems: string[]}
     */
    public function preview(SellerOrder $so): array
    {
        $so->loadMissing(['order', 'seller', 'items']);
        /** @var Order $order */
        $order    = $so->order;
        $problems = [];
        $live     = config('intigo.mode') === 'live';

        // ── Who: never a TEST account's parcel at a real courier ──
        if ($live) {
            if (!$so->isPlatformParcel() && TestAccounts::isTestUser((int) $so->seller_id)) {
                $problems[] = 'TEST seller account: TEST orders are never sent to Intigo in live mode';
            }
            if (TestAccounts::isTestUser((int) $order->user_id)) {
                $problems[] = 'TEST buyer account: TEST orders are never sent to Intigo in live mode';
            }
        }

        // ── Pickup: this seller's own index, verified and created at Intigo ──
        $pickup = self::pickupIndex($so);
        if ($pickup === null) {
            $problems[] = $so->isPlatformParcel()
                ? 'seller has no Intigo pickup address'
                : 'seller has no Intigo pickup address: ' . PickupAddressService::HOLD . ' (create it from the seller page; the parcel is re-sent automatically)';
        } elseif (!$so->isPlatformParcel()) {
            if (!SellerPickup::intigoReady($so->seller_id)) {
                // Changed / unverified address: never send the driver to the old one
                $problems[] = PickupAddressService::HOLD . ' (verify it and create it in Intigo from the seller page; the parcel is re-sent automatically)';
            }
            $platform = config('intigo.platform_pickup_index');
            if (is_numeric($platform) && (int) $platform === $pickup) {
                $problems[] = "pickup index {$pickup} is the platform's own pickup, not this seller's";
            }
            $others = User::where('intigo_pickup_index', $pickup)->where('id', '!=', $so->seller_id)->pluck('id')->all();
            if ($others) {
                $problems[] = "pickup index {$pickup} is also set on seller(s) #" . implode(', #', $others) . ': one index per seller (fix it on the seller pages)';
            }
        }

        // ── Money ──
        if (($order->payment_method ?? 'cod') !== 'cod') {
            $problems[] = 'not a cash-on-delivery order (Intigo parcels are COD only)';
        }
        $priceM = self::codMillimes($so);
        if ($priceM <= 0) {
            $problems[] = 'cash-on-delivery amount is ' . Millimes::toDecimal($priceM) . ' TND (nothing to collect: a 100% discount?)';
        }

        // ── Client: the checkout snapshot on the order (never the profile) ──
        $phone1 = TunisianPhone::normalize((string) $order->phone);
        if (!preg_match('/^\d{8}$/', (string) $phone1)) {
            $problems[] = 'client phone is not 8 digits ("' . $order->phone . '")';
        }
        $phone2 = $order->phone_secondary ? TunisianPhone::normalize((string) $order->phone_secondary) : null;

        $recipient = trim((string) ($order->recipient_name ?: $order->user?->name));
        $street    = trim((string) $order->address);
        $city      = $order->city_name ?: (IntigoRegions::cityNameForWilaya($order->wilaya) ?? $order->wilaya);
        $district  = $order->district_name ?: $order->delegation;

        if ($recipient === '')  $problems[] = 'client name missing';
        if ($street === '')     $problems[] = 'street address missing';
        if (!$city)             $problems[] = 'governorate missing';

        [$city, $district, $regionProblem] = self::intigoNames($city, $district, $live);
        if ($regionProblem) $problems[] = $regionProblem;

        // Landmark / delivery notes help the driver find the client
        $destination = $street . ($order->notes ? ' — ' . trim($order->notes) : '');

        $payload = [
            'recipient_name'      => mb_substr($recipient, 0, 255),
            'phone1'              => $phone1,
            'destination_address' => mb_substr($destination, 0, 500),
            'city_name'           => $city,
            'district_name'       => $district ?: $city,
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

        return ['payload' => $payload, 'problems' => $problems];
    }

    /**
     * The client's governorate / delegation in Intigo's exact spelling, or why
     * they don't match Intigo's lists (by-name: an unknown governorate is a
     * 400, an unknown delegation a silent guess). Without synced regions the
     * names can't be checked: refused in live mode.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} city, district, problem
     */
    public static function intigoNames(?string $city, ?string $district, bool $live): array
    {
        $city     = is_string($city) && trim($city) !== '' ? trim($city) : null;
        $district = is_string($district) && trim($district) !== '' ? trim($district) : null;

        if (!IntigoRegions::available()) {
            return [$city, $district, $live ? "Intigo regions not synced: the client's governorate / delegation can't be checked (php artisan intigo:sync-regions)" : null];
        }
        if ($city === null) {
            return [null, $district, null];   // already reported as "governorate missing"
        }
        $c = DB::table('intigo_cities')->whereRaw('LOWER(name) = ?', [mb_strtolower($city)])->first(['id', 'name']);
        if (!$c) {
            return [$city, $district, "client governorate \"{$city}\" is not one of Intigo's governorates"];
        }
        if ($district === null) {
            return [$c->name, null, "client delegation missing (Intigo needs one of {$c->name}'s delegations)"];
        }
        $d = DB::table('intigo_districts')->where('city_id', $c->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($district)])->value('name');
        if (!$d) {
            return [$c->name, $district, "client delegation \"{$district}\" is not one of Intigo's delegations of {$c->name}"];
        }
        return [$c->name, $d, null];
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
