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
 *   GET /api/admin/delivery-settings  — per-size prices, margins, payment methods, Intigo switch, history
 *   PUT /api/admin/delivery-settings  — save (TND, ≥ 0, max 3 decimals); every change is logged
 *
 * Prices are per parcel size (S / L / XL): client fee, Intigo delivery cost,
 * Intigo return fee, seller contribution for free shipping. The flat fields
 * (client_delivery_fee…) are still accepted and mean size S.
 *
 * New values apply to orders placed afterwards only: each parcel keeps the
 * values frozen at its checkout.
 */
class DeliverySettingsController extends Controller
{
    private const AMOUNT = ['regex:/^\d{1,9}(\.\d{1,3})?$/'];

    /** Flat (size S) fields, accepted when `sizes` isn't sent. */
    private const FLAT_SIZE_FIELDS = ['client_delivery_fee', 'agency_delivery_cost', 'seller_free_delivery_contribution', 'refused_parcel_agency_fee'];

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
            'return_shipping_fee'        => array_merge(['required'], self::AMOUNT),
            'refused_parcel_fee_paid_by' => 'required|in:' . implode(',', DeliverySettings::PAYERS),
            'intigo_can_open'            => 'sometimes|boolean',
            'payment_methods'            => 'sometimes|array',
            'payment_methods.*'          => 'boolean',
            'sizes'                      => 'sometimes|array',
        ];
        foreach (self::FLAT_SIZE_FIELDS as $field) {
            $rules[$field] = array_merge(['required_without:sizes'], self::AMOUNT);
        }
        foreach (ParcelSize::ALL as $size) {
            foreach (DeliverySettings::SIZE_AMOUNTS as $field) {
                $rules["sizes.{$size}.{$field}"] = array_merge(['required_with:sizes'], self::AMOUNT);
            }
        }
        $request->validate($rules, [
            'regex' => 'The :attribute must be an amount in TND, not negative, with at most 3 decimals.',
        ]);

        $values = [
            'return_shipping_fee'        => Millimes::of((string) $request->input('return_shipping_fee')),
            'refused_parcel_fee_paid_by' => $request->input('refused_parcel_fee_paid_by'),
        ];
        if ($request->has('intigo_can_open')) {
            $values['intigo_can_open'] = $request->boolean('intigo_can_open');
        }
        if ($request->has('sizes')) {
            foreach (ParcelSize::ALL as $size) {
                foreach (DeliverySettings::SIZE_AMOUNTS as $field) {
                    $values['sizes'][$size][$field] = Millimes::of((string) $request->input("sizes.{$size}.{$field}"));
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
            'message' => $changes ? 'Delivery settings saved. They apply to new orders only.' : 'Saved.',
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
                $warnings[] = "Size {$size}: normal shipments lose " . Millimes::toDecimal(-$m['normal']) . ' TND each: the client fee is below the Intigo cost.';
            }
            if ($m['free_delivery'] < 0) {
                $warnings[] = "Size {$size}: free-delivery shipments lose " . Millimes::toDecimal(-$m['free_delivery']) . ' TND each: the seller contribution is below the Intigo cost.';
            }
        }

        $settings = [];
        foreach (DeliverySettings::AMOUNTS as $field) {
            $settings[$field] = $f($v[$field]);
        }
        $settings['refused_parcel_fee_paid_by'] = $v['refused_parcel_fee_paid_by'];
        $settings['intigo_can_open']            = $v['intigo_can_open'];

        $sizes = [];
        foreach ($v['sizes'] as $size => $r) {
            $sizes[$size] = array_map($f, $r) + [
                'package_size'  => ParcelSize::packageSize($size),
                'margin_normal' => $f($margins['sizes'][$size]['normal']),
                'margin_free'   => $f($margins['sizes'][$size]['free_delivery']),
            ];
        }

        return [
            'settings'        => $settings,
            'sizes'           => $sizes,
            'margins'         => ['normal' => $f($margins['normal']), 'free_delivery' => $f($margins['free_delivery'])],
            'warnings'        => $warnings,
            'payment_methods' => $this->paymentMethods->all(),
            'intigo'          => ['mode' => config('intigo.mode'), 'can_open' => $v['intigo_can_open']],
            'updated_at'      => $v['updated_at'],
            'history'         => $this->settings->history(),
        ];
    }
}
