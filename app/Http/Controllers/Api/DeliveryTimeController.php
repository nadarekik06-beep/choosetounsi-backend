<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Delivery\DeliveryTimeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *   GET /api/delivery-time         public, read-only: the rules behind the storefront delivery estimate
 *   GET /api/admin/delivery-time   same, for the admin panel
 *   PUT /api/admin/delivery-time   admin edit
 */
class DeliveryTimeController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => DeliveryTimeSettings::all()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup_delay_min_business_days' => ['required', 'integer', 'min:0', 'max:30'],
            'pickup_delay_max_business_days' => ['required', 'integer', 'min:0', 'max:30', 'gte:pickup_delay_min_business_days'],
            'transit_business_days'          => ['required', 'integer', 'min:0', 'max:30'],
            'working_days'                   => ['required', 'array', 'min:1'],
            'working_days.*'                 => ['integer', 'between:1,7'],
            'holidays'                       => ['present', 'array', 'max:400'],
            'holidays.*'                     => ['date_format:Y-m-d'],
            'order_cutoff_time'              => ['required', 'date_format:H:i'],
        ]);

        return response()->json(['success' => true, 'data' => DeliveryTimeSettings::update($data, $request->user()?->id)]);
    }
}
