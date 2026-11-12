<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Delivery\DeliverySettings;
use App\Services\Payments\CheckoutPaymentMethods;
use App\Support\Millimes;
use App\Support\ParcelSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin "Delivery & Fees" page.
 *   GET /api/admin/delivery-settings  — per-size prices, margins, payment methods, Intigo switches, history
 *   PUT /api/admin/delivery-settings  — save (TND, ≥ 0, max 3 decimals); every change is logged
 *
 * Prices are per parcel size (S / L / XL): client fee (included in the displayed
 * price of every product of that size: delivery is shown as free), Intigo
 * delivery cost, Intigo return fee. The flat fields (client_delivery_fee…) are
 * still accepted and mean size S. The old seller contribution for free shipping
 * is accepted and ignored (older admin builds still send it).
 *
 * New values apply to orders placed afterwards only: each parcel keeps the
 * values frozen at its checkout.
 */
class DeliverySettingsController extends Controller
{
    private const AMOUNT = ['regex:/^\d{1,9}(\.\d{1,3})?$/'];

    /** Flat (size S) fields, accepted when `sizes` isn't sent. */
    private const FLAT_SIZE_FIELDS = ['client_delivery_fee', 'agency_delivery_cost', 'refused_parcel_agency_fee'];

    public function __construct(
        private DeliverySettings       $settings,
        private CheckoutPaymentMethods $paymentMethods,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'refused_parcel_fee_paid_by' => 'required|in:' . implode(',', DeliverySettings::PAYERS),
            'intigo_can_open'            => 'sometimes|boolean',
            'intigo_is_exchange'         => 'sometimes|boolean',
            'payment_methods'            => 'sometimes|array',
            'payment_methods.*'          => 'boolean',
            'sizes'                      => 'sometimes|array',
        ];
        foreach (self::FLAT_SIZE_FIELDS as $field) {
            $rules[$field] = array_merge(['required_without:sizes'], self::AMOUNT);
        }
        foreach (ParcelSize::ALL as $size) {
            foreach (DeliverySettings::EDITABLE_SIZE_AMOUNTS as $field) {
                $rules["sizes.{$size}.{$field}"] = array_merge(['required_with:sizes'], self::AMOUNT);
            }
            // After-sale fees (complaints): optional, so older clients keep working
            foreach (DeliverySettings::AFTER_SALE_AMOUNTS as $field) {
                $rules["sizes.{$size}.{$field}"] = array_merge(['sometimes'], self::AMOUNT);
            }
            $rules["sizes.{$size}." . DeliverySettings::AFTER_SALE_SYNC] = 'sometimes|boolean';
        }
        $request->validate($rules, [
            'regex' => 'The :attribute must be an amount in TND, not negative, with at most 3 decimals.',
        ]);

        $values = [
            'refused_parcel_fee_paid_by' => $request->input('refused_parcel_fee_paid_by'),
        ];
        if ($request->has('intigo_can_open')) {
            $values['intigo_can_open'] = $request->boolean('intigo_can_open');
        }
        if ($request->has('intigo_is_exchange')) {
            $values['intigo_is_exchange'] = $request->boolean('intigo_is_exchange');
        }
        if ($request->has('sizes')) {
            foreach (ParcelSize::ALL as $size) {
                foreach (DeliverySettings::EDITABLE_SIZE_AMOUNTS as $field) {
                    $values['sizes'][$size][$field] = Millimes::of((string) $request->input("sizes.{$size}.{$field}"));
                }
                foreach (DeliverySettings::AFTER_SALE_AMOUNTS as $field) {
                    if ($request->has("sizes.{$size}.{$field}")) {
                        $values['sizes'][$size][$field] = Millimes::of((string) $request->input("sizes.{$size}.{$field}"));
                    }
                }
                $sync = "sizes.{$size}." . DeliverySettings::AFTER_SALE_SYNC;
                if ($request->has($sync)) {
                    $values['sizes'][$size][DeliverySettings::AFTER_SALE_SYNC] = $request->boolean($sync);
                }
            }
        } else {
            foreach (self::FLAT_SIZE_FIELDS as $field) {
                $values[$field] = Millimes::of((string) $request->input($field));
            }
        }

        $changes = $this->settings->update($values, $request->user()->id);
        if ($request->has('payment_methods')) {
            $this->paymentMethods->set((array) $request->input('payment_methods'), $request->user()->id);
        }

        return response()->json([
            'success' => true,
            'message' => $changes ? 'Delivery settings saved. Displayed prices follow at once; existing orders keep their amounts.' : 'Saved.',
            'data'    => $this->payload(),
        ]);
    }

    private function payload(): array
    {
        $v = $this->settings->all();
        $f = fn (int $m) => Millimes::toFloat($m);
        $margins = $this->settings->margins($v);

        $warnings = [];
        foreach ($margins['sizes'] as $size => $m) {
            if ($m['normal'] < 0) {
                $warnings[] = "Size {$size}: parcels lose " . Millimes::toDecimal(-$m['normal']) . ' TND each: the client fee is below the Intigo cost.';
            }
        }

        $settings = [];
        foreach (DeliverySettings::AMOUNTS as $field) {
            if ($field !== 'seller_free_delivery_contribution') {
                $settings[$field] = $f($v[$field]);
            }
        }
        $settings['refused_parcel_fee_paid_by'] = $v['refused_parcel_fee_paid_by'];
        $settings['intigo_can_open']            = $v['intigo_can_open'];
        $settings['intigo_is_exchange']         = $v['intigo_is_exchange'];

        $sizes = [];
        foreach ($v['sizes'] as $size => $r) {
            unset($r['seller_contribution']);
            $sizes[$size] = array_map($f, $r) + [
                DeliverySettings::AFTER_SALE_SYNC => $v['after_sale_sync'][$size] ?? true,
                'package_size'  => ParcelSize::packageSize($size),
                'margin_normal' => $f($margins['sizes'][$size]['normal']),
            ];
        }

        return [
            'settings'        => $settings,
            'sizes'           => $sizes,
            'margins'         => ['normal' => $f($margins['normal'])],
            'warnings'        => $warnings,
            'payment_methods' => $this->paymentMethods->all(),
            'intigo'          => ['mode' => config('intigo.mode'), 'can_open' => $v['intigo_can_open'], 'is_exchange' => $v['intigo_is_exchange']],
            'updated_at'      => $v['updated_at'],
            'history'         => $this->settings->history(),
        ];
    }
}
