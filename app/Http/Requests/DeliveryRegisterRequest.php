<?php
// app/Http/Requests/DeliveryRegisterRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class DeliveryRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->input('role');

        $base = [
            'role'     => ['required', 'in:delivery_admin,delivery_guy'],
            'name'     => ['required', 'string', 'max:100'],
            'email'    => [
                'required',
                'email:rfc',
                // Block duplicate only when SAME email + SAME role already exists
                Rule::unique('users', 'email')->where(
                    fn($query) => $query->where('role', $role)
                ),
            ],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];

        if ($role === 'delivery_admin') {
            return array_merge($base, [
                'company_name'        => ['required', 'string', 'max:150'],
                'phone'               => ['required', 'string', 'regex:/^[0-9+\s\-]{7,20}$/'],
                'address'             => ['required', 'string', 'max:255'],
                'wilaya'              => ['nullable', 'string', 'max:100'],
                'city'                => ['nullable', 'string', 'max:100'],
                'website'             => ['nullable', 'url', 'max:255'],
                'registration_number' => ['nullable', 'string', 'max:50'],
                'description'         => ['nullable', 'string', 'max:1000'],
            ]);
        }

        // delivery_guy
        return array_merge($base, [
            'phone'          => ['required', 'string', 'regex:/^[0-9+\s\-]{7,20}$/'],
            'wilaya'         => ['nullable', 'string', 'max:100'],
            'city'           => ['nullable', 'string', 'max:100'],
            'vehicle_type'   => ['nullable', 'in:moto,car,van,bicycle,on_foot'],
            'vehicle_plate'  => ['nullable', 'string', 'max:20'],
            'id_card_number' => ['nullable', 'string', 'max:50'],
        ]);
    }

    public function messages(): array
    {
        $roleLabel = $this->input('role') === 'delivery_admin'
            ? 'delivery company'
            : 'delivery guy';

        return [
            'email.unique'       => "This email is already registered as a {$roleLabel}.",
            'password.confirmed' => 'Passwords do not match.',
            'role.in'            => 'Invalid role selected.',
            'phone.regex'        => 'Please enter a valid phone number.',
        ];
    }
}