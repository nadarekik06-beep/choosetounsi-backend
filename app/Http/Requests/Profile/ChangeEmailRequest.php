<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/profile/email — sends a 6-digit code to the new address. */
class ChangeEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email'            => ['required', 'email:rfc', 'max:191', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'current_password' => [$this->user()->has_password ? 'required' : 'nullable', 'string'],
        ];
    }
}
