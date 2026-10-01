<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The buyer's shipping address: one set of rules shared by checkout,
 * buy-now and the address book, so a saved address always passes checkout.
 *
 * Request keys (= orders / user_addresses columns):
 *   recipient_name, phone, phone_secondary?, wilaya, delegation,
 *   address (street), postal_code, notes? (landmark / delivery notes)
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
        return [
            'recipient_name'  => ['required', 'string', 'min:3', 'max:120'],
            'phone'           => ['required', 'string', 'regex:' . TunisianPhone::PATTERN],
            'phone_secondary' => ['nullable', 'string', 'regex:' . TunisianPhone::PATTERN, 'different:phone'],
            'wilaya'          => ['required', 'string', Rule::in(Wilayas::ALL)],
            'delegation'      => ['required', 'string', 'min:2', 'max:100'],
            'address'         => ['required', 'string', 'min:5', 'max:500'],
            'postal_code'     => ['required', 'digits:4'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Validated request → order / address-book columns (trimmed, nulls for blanks). */
    public static function columns(Request $request): array
    {
        $clean = fn($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'recipient_name'  => $clean($request->recipient_name),
            'phone'           => $request->phone,
            'phone_secondary' => $request->phone_secondary ?: null,
            'wilaya'          => $request->wilaya,
            'delegation'      => $clean($request->delegation),
            'address'         => $clean($request->address),
            'postal_code'     => $request->postal_code,
            'notes'           => $clean($request->notes),
        ];
    }
}
