<?php

namespace App\Support;

/**
 * "Sur commande" fields shared by the seller and admin product endpoints.
 * The client is never trusted: when is_made_to_order is false, preparation_days is forced to NULL.
 */
class MadeToOrder
{
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 30;

    public static function rules(): array
    {
        return [
            'is_made_to_order' => ['nullable', function ($attribute, $value, $fail) {
                // Accepts true/false, 1/0 and their string forms (multipart FormData sends strings)
                if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                    $fail('The ' . $attribute . ' field must be true or false.');
                }
            }],
            'preparation_days' => ['nullable', 'required_if:is_made_to_order,1,true,on,yes', 'integer', 'min:' . self::MIN_DAYS, 'max:' . self::MAX_DAYS],
        ];
    }

    /**
     * Attributes to write on the product. Returns [] when the request does not carry
     * is_made_to_order and $onlyIfPresent (partial updates leave the product unchanged).
     */
    public static function attributes(array $input, bool $onlyIfPresent = false): array
    {
        if ($onlyIfPresent && !array_key_exists('is_made_to_order', $input)) {
            return [];
        }
        $on = filter_var($input['is_made_to_order'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return [
            'is_made_to_order' => $on,
            'preparation_days' => $on ? (int) $input['preparation_days'] : null,
        ];
    }
}
