<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserAddress;

/**
 * Shoppers may skip /complete-profile, so checkout is where their details
 * arrive: after an order, copy the (already validated) delivery address into
 * an incomplete profile.
 *
 * Only empty fields are filled — nothing the shopper saved is overwritten.
 * The address goes into the address book as a new entry (default only when it
 * is the first one); a saved address with the same street just gets its empty
 * fields filled, so no duplicate appears.
 */
class ProfileFromCheckout
{
    /** @param array $columns ShippingAddress::columns() of the checkout request */
    public static function fill(User $user, array $columns): void
    {
        $completion = $user->profileCompletion();
        if (!$completion->isEnforced() || $completion->isComplete()) {
            return;
        }

        self::fillPersonal($user, $columns);

        if (!ProfileCompletion::hasUsableAddress($user->id)) {
            self::saveAddress($user, $columns);
        }
    }

    private static function fillPersonal(User $user, array $columns): void
    {
        $parts = preg_split('/\s+/u', trim((string) ($columns['recipient_name'] ?? '')), 2) ?: [];
        $fill  = [];

        if (!filled($user->first_name) && filled($parts[0] ?? null)) {
            $fill['first_name'] = $parts[0];
        }
        if (!filled($user->last_name) && filled($parts[1] ?? null)) {
            $fill['last_name'] = $parts[1];
        }
        if (!filled($user->phone) && TunisianPhone::isValid($columns['phone'] ?? null)) {
            $fill['phone'] = $columns['phone'];
        }
        if ($fill) {
            $user->forceFill($fill)->save();
        }
    }

    private static function saveAddress(User $user, array $columns): void
    {
        $fields = array_intersect_key($columns, array_flip([
            'recipient_name', 'phone', 'phone_secondary', 'wilaya', 'delegation',
            'intigo_city_id', 'intigo_district_id', 'city_name', 'district_name',
            'address', 'postal_code',
        ]));
        if (!filled($fields['wilaya'] ?? null) || !filled($fields['delegation'] ?? null) || !filled($fields['address'] ?? null)) {
            return;
        }

        $street = mb_strtolower(trim($fields['address']));
        $same   = UserAddress::where('user_id', $user->id)->get()
            ->first(fn (UserAddress $a) => mb_strtolower(trim((string) $a->address)) === $street);

        if ($same) {
            $empty = array_filter($fields, fn ($v, $k) => filled($v) && !filled($same->{$k}), ARRAY_FILTER_USE_BOTH);
            if ($empty) {
                $same->update($empty);
            }
            return;
        }

        UserAddress::create($fields + [
            'user_id'    => $user->id,
            'label'      => 'Home',
            'is_default' => !UserAddress::where('user_id', $user->id)->exists(),
        ]);
    }
}
