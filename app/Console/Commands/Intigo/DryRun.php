<?php

namespace App\Console\Commands\Intigo;

use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\Intigo\ParcelPayload;
use App\Support\Millimes;
use App\Support\SellerPickup;
use App\Support\TestAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: the exact POST /parcels/by-name body each parcel of an order
 * would get, next to the data it comes from (seller pickup, the client's
 * checkout address, amounts) and every reason it would be refused.
 * Never calls Intigo, never writes anything.
 *
 *   php artisan intigo:dry-run --order=12
 *   php artisan intigo:dry-run --seller-order=31 --json
 *   --as-live   apply the live-mode rules (TEST accounts, regions) whatever INTIGO_MODE says
 */
class DryRun extends Command
{
    protected $signature = 'intigo:dry-run {--order=* : order id or number} {--seller-order=* : seller_order id} {--as-live : apply the live-mode rules} {--json : machine-readable output}';

    protected $description = 'Show the Intigo parcel payloads an order would produce (read-only, nothing is sent).';

    public function handle(ParcelPayload $payloads): int
    {
        if ($this->option('as-live')) {
            config(['intigo.mode' => 'live']);   // this process only: preview() reads no Intigo endpoint
        }

        $ids = collect($this->option('seller-order'))->map(fn ($v) => (int) $v)->filter();
        foreach ($this->option('order') as $ref) {
            $orderId = is_numeric($ref) ? (int) $ref : (int) Order::where('order_number', $ref)->value('id');
            $ids = $ids->merge(SellerOrder::where('order_id', $orderId)->orderBy('id')->pluck('id'));
        }
        if ($ids->isEmpty()) {
            $this->error('Give --order=<id|number> or --seller-order=<id>.');
            return self::FAILURE;
        }

        $report = [];
        foreach (SellerOrder::with(['order.user', 'seller', 'items'])->whereIn('id', $ids->unique())->orderBy('id')->get()->groupBy('order_id') as $parcels) {
            $report[] = $this->order($parcels->first()->order, $parcels, $payloads);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($report as $o) {
            $this->print($o);
        }
        $this->line('<comment>Dry run (mode rules: ' . config('intigo.mode') . '): nothing was sent to Intigo.</comment>');
        return self::SUCCESS;
    }

    private function order(Order $order, $parcels, ParcelPayload $payloads): array
    {
        $profile = DB::table('user_addresses')->where('user_id', $order->user_id)->orderByDesc('is_default')->orderByDesc('id')->first();
        $codSum  = 0;
        $out = [
            'order' => [
                'id' => $order->id, 'number' => $order->order_number, 'status' => $order->status,
                'payment_method' => $order->payment_method,
                'total_amount' => (float) $order->total_amount, 'subtotal' => (float) $order->subtotal,
                'discount_amount' => (float) $order->discount_amount, 'shipping_fee' => (float) $order->shipping_fee,
                'coupon_codes' => $order->coupon_codes,
            ],
            'buyer' => ['id' => $order->user_id, 'test_account' => TestAccounts::isTestUser((int) $order->user_id)],
            'client_checkout_address' => [
                'recipient_name' => $order->recipient_name, 'phone' => $order->phone, 'phone_secondary' => $order->phone_secondary,
                'address' => $order->address, 'notes' => $order->notes, 'postal_code' => $order->postal_code,
                'wilaya' => $order->wilaya, 'delegation' => $order->delegation,
                'city_name' => $order->city_name, 'district_name' => $order->district_name,
            ],
            'client_profile_address_now' => $profile ? [
                'recipient_name' => $profile->recipient_name ?? null, 'phone' => $profile->phone ?? null,
                'address' => $profile->address ?? null, 'city_name' => $profile->city_name ?? null, 'district_name' => $profile->district_name ?? null,
            ] : null,
            'parcels' => [],
        ];

        foreach ($parcels as $so) {
            $pickup  = SellerPickup::for($so->seller, $so->seller_id);
            $preview = $payloads->preview($so);
            $codM    = ParcelPayload::codMillimes($so);
            $codSum += $codM;
            $out['parcels'][] = [
                'seller_order_id' => $so->id,
                'seller' => [
                    'id' => $so->seller_id, 'shop' => $pickup['shop_name'], 'test_account' => TestAccounts::isTestUser((int) $so->seller_id),
                    'intigo_pickup_index' => ParcelPayload::pickupIndex($so), 'intigo_pickup_label' => $so->seller?->intigo_pickup_label,
                ],
                'seller_pickup' => [
                    'address' => SellerPickup::formatAddress($pickup), 'phone' => $pickup['phone'],
                    'latitude' => $pickup['latitude'], 'longitude' => $pickup['longitude'],
                    'status' => $pickup['status'], 'intigo_ready' => $pickup['intigo_ready'],
                ],
                'amounts' => [
                    'items_subtotal' => (float) $so->subtotal, 'coupon_code' => $so->coupon_code,
                    'coupon_discount' => (float) $so->discount_amount, 'free_delivery' => (bool) $so->is_free_delivery,
                    'delivery_fee_client' => (float) $so->delivery_fee, 'parcel_size' => $so->parcel_size,
                    'cod_amount' => Millimes::toFloat($codM),
                    'check_items_minus_coupon_plus_fee' => Millimes::toFloat(Millimes::of($so->subtotal) - Millimes::of($so->discount_amount) + Millimes::of($so->delivery_fee)),
                ],
                'status' => $so->status, 'intigo_nid' => $so->intigo_nid, 'intigo_sync_status' => $so->intigo_sync_status,
                'payload' => $preview['payload'],
                'would_send' => $preview['problems'] === [] && !$so->intigo_nid,
                'problems' => $preview['problems'],
            ];
        }
        $out['check'] = [
            'sum_cod' => Millimes::toFloat($codSum),
            'order_total' => (float) $order->total_amount,
            'sum_cod_equals_order_total' => $order->payment_method !== 'cod' || $codSum === Millimes::of($order->total_amount),
        ];
        return $out;
    }

    private function print(array $o): void
    {
        $this->newLine();
        $this->info("══ Order {$o['order']['number']} (#{$o['order']['id']}) · {$o['order']['status']} · {$o['order']['payment_method']} · total {$o['order']['total_amount']} TND"
            . ($o['buyer']['test_account'] ? ' · TEST buyer' : ''));
        $a = $o['client_checkout_address'];
        $this->line("Client (checkout snapshot): {$a['recipient_name']} · {$a['phone']}" . ($a['phone_secondary'] ? " / {$a['phone_secondary']}" : '')
            . " · {$a['address']} · {$a['district_name']} / {$a['city_name']} (wilaya {$a['wilaya']}, delegation {$a['delegation']})");
        if ($p = $o['client_profile_address_now']) {
            $this->line("Client profile address now (NOT used): {$p['recipient_name']} · {$p['phone']} · {$p['address']} · {$p['district_name']} / {$p['city_name']}");
        }
        foreach ($o['parcels'] as $parcel) {
            $s = $parcel['seller'];
            $m = $parcel['amounts'];
            $this->newLine();
            $this->line("── Parcel S{$parcel['seller_order_id']} · seller #{$s['id']} {$s['shop']}" . ($s['test_account'] ? ' (TEST)' : '')
                . " · pickup index " . ($s['intigo_pickup_index'] ?? 'none') . ($s['intigo_pickup_label'] ? " = {$s['intigo_pickup_label']}" : ''));
            $this->line("   Seller pickup in our DB: {$parcel['seller_pickup']['address']} · {$parcel['seller_pickup']['phone']} · status {$parcel['seller_pickup']['status']}");
            $this->line("   Amounts: items {$m['items_subtotal']} − coupon {$m['coupon_discount']}" . ($m['coupon_code'] ? " ({$m['coupon_code']})" : '')
                . " + delivery {$m['delivery_fee_client']}" . ($m['free_delivery'] ? ' (free delivery)' : '') . " = COD {$m['cod_amount']} (size {$m['parcel_size']})");
            $this->line(json_encode($parcel['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $parcel['problems']
                ? $this->error('   Would be REFUSED: ' . implode(' | ', $parcel['problems']))
                : ($parcel['intigo_nid'] ? $this->line("   Already sent: {$parcel['intigo_nid']}") : $this->info('   Would be sent as above.'));
        }
        $c = $o['check'];
        $this->line("Σ COD {$c['sum_cod']} vs order total {$c['order_total']}: " . ($c['sum_cod_equals_order_total'] ? '<info>equal</info>' : '<error>DIFFERENT</error>'));
    }
}
