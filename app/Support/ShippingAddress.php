<?php

namespace App\Support;

use App\Services\Intigo\IntigoRegions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The buyer's shipping address: one set of rules shared by checkout,
 * buy-now and the address book, so a saved address always passes checkout.
 *
 * Request keys (= orders / user_addresses columns):
 *   recipient_name, phone, phone_secondary?, wilaya, delegation,
 *   address (street), postal_code?, notes? (landmark / delivery notes)
 *   intigo_city_id, intigo_district_id: the governorate and delegation picked
 *   from Intigo's lists (App\Services\Intigo\IntigoRegions). Once the regions
 *   are synced they are required, and they set wilaya / delegation /
 *   city_name / district_name (the exact names sent to the courier).
 *   Before that (regions not synced), the free-text wilaya / delegation apply.
 *
 * Phones: spaces, dashes, "+216" and "00216" are stripped, then 8 digits.
 */
class ShippingAddress
{
    /** Normalize phones and wilaya in place, before validation runs. */
    public static function prepare(Request $request): void
    {
        $merge = [];
        foreach (['phone', 'phone_secondary'] as $key) {
            if ($request->has($key)) {
                $merge[$key] = TunisianPhone::normalize($request->input($key)) ?: null;
            }
        }
        if ($request->filled('wilaya')) {
            // Unknown spellings stay as typed so the in: rule rejects them.
            $merge['wilaya'] = Wilayas::normalize($request->input('wilaya')) ?? $request->input('wilaya');
        }
        if ($request->has('postal_code')) {
            $merge['postal_code'] = is_string($request->postal_code) ? trim($request->postal_code) : $request->postal_code;
        }
        $request->merge($merge);
    }

    public static function rules(): array
    {
        if (IntigoRegions::available()) {
            return self::common() + [
                'intigo_city_id'     => ['required', 'integer'],
                'intigo_district_id' => ['required', 'integer', function ($attr, $value, $fail) {
                    if (!IntigoRegions::resolve((int) request()->input('intigo_city_id'), (int) $value)) {
                        $fail(__('messages.checkout.reselect_region'));
                    }
                }],
            ];
        }
        return self::common() + [
            'wilaya'     => ['required', 'string', Rule::in(Wilayas::ALL)],
            'delegation' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    private static function common(): array
    {
        return [
            'recipient_name'  => ['required', 'string', 'min:3', 'max:120'],
            'phone'           => ['required', 'string', 'regex:' . TunisianPhone::PATTERN],
            'phone_secondary' => ['nullable', 'string', 'regex:' . TunisianPhone::PATTERN, 'different:phone'],
            'address'         => ['required', 'string', 'min:5', 'max:500'],
            'postal_code'     => ['nullable', 'digits:4'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Validated request → order / address-book columns (trimmed, nulls for blanks). */
    public static function columns(Request $request): array
    {
        $clean  = fn($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $region = IntigoRegions::available()
            ? IntigoRegions::resolve((int) $request->input('intigo_city_id'), (int) $request->input('intigo_district_id'))
            : null;

        return [
            'recipient_name'  => $clean($request->recipient_name),
            'phone'           => $request->phone,
            'phone_secondary' => $request->phone_secondary ?: null,
            'wilaya'          => $region['wilaya'] ?? $request->wilaya,
            'delegation'      => $region['district_name'] ?? $clean($request->delegation),
            'intigo_city_id'     => $region['city_id'] ?? null,
            'intigo_district_id' => $region['district_id'] ?? null,
            'city_name'          => $region['city_name'] ?? null,
            'district_name'      => $region['district_name'] ?? null,
            'address'         => $clean($request->address),
            'postal_code'     => $clean($request->postal_code),
            'notes'           => $clean($request->notes),
        ];
    }
}
