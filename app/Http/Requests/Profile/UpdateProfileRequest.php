<?php

namespace App\Http\Requests\Profile;

use App\Support\TunisianPhone;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/profile — personal info. E-mail changes go through ChangeEmailRequest. */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['first_name', 'last_name'] as $key) {
            if (is_string($this->input($key))) {
                $merge[$key] = preg_replace('/\s+/u', ' ', trim($this->input($key)));
            }
        }
        if ($this->has('phone')) {
            $merge['phone'] = TunisianPhone::normalize($this->input('phone')) ?: null;
        }
        foreach (['date_of_birth', 'gender'] as $key) {
            if ($this->has($key) && $this->input($key) === '') {
                $merge[$key] = null;
            }
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        // Letters (any script), spaces, apostrophes and hyphens: "Ben Ali", "N'Diaye", "عبد الله".
        $name = ['string', 'min:2', 'max:60', "regex:/^[\pL\pM' \-]+$/u"];

        return [
            'first_name'    => ['sometimes', 'required', ...$name],
            'last_name'     => ['sometimes', 'required', ...$name],
            'phone'         => ['sometimes', 'required', 'string', 'regex:' . TunisianPhone::PATTERN],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:-13 years', 'after:1900-01-01'],
            'gender'        => ['sometimes', 'nullable', 'in:male,female'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.regex'     => __('messages.profile.name_letters'),
            'last_name.regex'      => __('messages.profile.name_letters'),
            'phone.regex'          => __('messages.profile.phone_invalid'),
            'date_of_birth.before' => __('messages.profile.too_young'),
        ];
    }

    public function attributes(): array
    {
        return [
            'first_name'    => __('messages.profile.fields.first_name'),
            'last_name'     => __('messages.profile.fields.last_name'),
            'date_of_birth' => __('messages.profile.fields.date_of_birth'),
        ];
    }
}
