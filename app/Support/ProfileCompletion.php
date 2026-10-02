<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserAddress;

/**
 * How complete a shopper's profile is.
 *
 * Required before checkout (role = client only — sellers, admins and delivery
 * accounts are never blocked): first name, last name, phone, and one delivery
 * address with governorate + city + street. Optional fields only raise the
 * percentage.
 *
 * Computed on the fly (no column) so it can never go stale when an address is
 * deleted or a field is cleared.
 */
class ProfileCompletion
{
    /** field => [weight, required] — weights add up to 100. */
    public const FIELDS = [
        'first_name'    => [20, true],
        'last_name'     => [20, true],
        'phone'         => [20, true],
        'address'       => [20, true],
        'avatar'        => [10, false],
        'date_of_birth' => [5, false],
        'gender'        => [5, false],
    ];

    private ?array $filled = null;

    public function __construct(private User $user) {}

    /** Only shoppers have to complete their profile. */
    public function isEnforced(): bool
    {
        return $this->user->role === 'client';
    }

    /** Required fields done (always true for roles the rule doesn't apply to). */
    public function isComplete(): bool
    {
        if (!$this->isEnforced()) {
            return true;
        }
        foreach (self::FIELDS as $field => [, $required]) {
            if ($required && !$this->filled()[$field]) {
                return false;
            }
        }
        return true;
    }

    public function percent(): int
    {
        $total = 0;
        foreach (self::FIELDS as $field => [$weight]) {
            if ($this->filled()[$field]) {
                $total += $weight;
            }
        }
        return $total;
    }

    /** @return array<int, array{field: string, required: bool}> */
    public function missing(): array
    {
        $out = [];
        foreach (self::FIELDS as $field => [, $required]) {
            if (!$this->filled()[$field]) {
                $out[] = ['field' => $field, 'required' => $required];
            }
        }
        return $out;
    }

    public function toArray(): array
    {
        return [
            'enforced' => $this->isEnforced(),
            'complete' => $this->isComplete(),
            'percent'  => $this->percent(),
            'missing'  => $this->missing(),
        ];
    }

    /** One complete delivery address (governorate + city + street). */
    public static function hasUsableAddress(int $userId): bool
    {
        return UserAddress::where('user_id', $userId)
            ->where('wilaya', '!=', '')
            ->whereNotNull('delegation')->where('delegation', '!=', '')
            ->where('address', '!=', '')
            ->exists();
    }

    private function filled(): array
    {
        if ($this->filled === null) {
            $u = $this->user;
            $this->filled = [
                'first_name'    => filled($u->first_name),
                'last_name'     => filled($u->last_name),
                'phone'         => TunisianPhone::isValid($u->phone),
                'address'       => self::hasUsableAddress($u->id),
                'avatar'        => filled($u->avatar),
                'date_of_birth' => $u->date_of_birth !== null,
                'gender'        => filled($u->gender),
            ];
        }
        return $this->filled;
    }
}
