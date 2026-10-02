<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/profile/notifications */
class NotificationPreferencesRequest extends FormRequest
{
    public const KEYS = ['email_updates', 'in_app_updates'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return collect(self::KEYS)->mapWithKeys(fn($k) => [$k => ['sometimes', 'boolean']])->all();
    }
}
