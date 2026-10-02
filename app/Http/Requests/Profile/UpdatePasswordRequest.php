<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/profile/password. Accounts created through Google never chose a
 * password (has_password = false): they set one without the current password.
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => [$this->user()->has_password ? 'required' : 'nullable', 'string'],
            'password'         => ['required', 'string', 'min:8', 'max:100', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ];
    }

    public function messages(): array
    {
        return ['password.regex' => __('messages.profile.password_weak')];
    }
}
