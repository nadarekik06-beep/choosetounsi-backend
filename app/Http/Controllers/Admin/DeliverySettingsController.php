<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Delivery\DeliverySettings;
use App\Services\Payments\CheckoutPaymentMethods;
use App\Support\Millimes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin "Delivery & Fees" page.
 *   GET /api/admin/delivery-settings  — values, platform margins, payment methods, change history
 *   PUT /api/admin/delivery-settings  — save (TND, ≥ 0, max 3 decimals); every change is logged
 *
 * New values apply to orders placed afterwards only: each parcel keeps the
 * values frozen at its checkout.
 */
class DeliverySettingsController extends Controller
{
    private const AMOUNT_RULE = ['required', 'regex:/^\d{1,9}(\.\d{1,3})?$/'];

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
        $rules = [];
        foreach (DeliverySettings::AMOUNTS as $field) {
            $rules[$field] = self::AMOUNT_RULE;
        }
        $request->validate($rules + [
            'refused_parcel_fee_paid_by' => 'required|in:' . implode(',', DeliverySettings::PAYERS),
            'payment_methods'            => 'sometimes|array',
            'payment_methods.*'          => 'boolean',
        ], [
            'regex' => 'The :attribute must be an amount in TND, not negative, with at most 3 decimals.',
        ]);

        $values = ['refused_parcel_fee_paid_by' => $request->input('refused_parcel_fee_paid_by')];
        foreach (DeliverySettings::AMOUNTS as $field) {
            $values[$field] = Millimes::of((string) $request->input($field));
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
        if ($margins['normal'] < 0) {
            $warnings[] = 'Normal shipments lose ' . Millimes::toDecimal(-$margins['normal']) . ' TND each: the client fee is below the agency cost.';
        }
        if ($margins['free_delivery'] < 0) {
            $warnings[] = 'Free-delivery shipments lose ' . Millimes::toDecimal(-$margins['free_delivery']) . ' TND each: the seller contribution is below the agency cost.';
        }

        $settings = [];
        foreach (DeliverySettings::AMOUNTS as $field) {
            $settings[$field] = $f($v[$field]);
        }
        $settings['refused_parcel_fee_paid_by'] = $v['refused_parcel_fee_paid_by'];

        return [
            'settings'        => $settings,
            'margins'         => ['normal' => $f($margins['normal']), 'free_delivery' => $f($margins['free_delivery'])],
            'warnings'        => $warnings,
            'payment_methods' => $this->paymentMethods->all(),
            'updated_at'      => $v['updated_at'],
            'history'         => $this->settings->history(),
        ];
    }
}
