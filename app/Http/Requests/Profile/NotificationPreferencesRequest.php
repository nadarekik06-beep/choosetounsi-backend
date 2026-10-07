<?php

namespace App\Http\Requests\Profile;

use App\Notifications\Support\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/profile/notifications
 *
 * Per-category channel choices — "{category}_{channel}" booleans for the channels
 * that aren't locked (NotificationPreferences::editableKeys(): orders_email,
 * reviews_in_app, promotions_email…). promotions_email is the marketing e-mail
 * consent. The two keys of the first version are still accepted.
 */
class NotificationPreferencesRequest extends FormRequest
{
    /** First version: one switch per channel for every category. */
    public const KEYS = ['email_updates', 'in_app_updates'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return collect(array_merge(self::KEYS, NotificationPreferences::editableKeys()))
            ->mapWithKeys(fn ($k) => [$k => ['sometimes', 'boolean']])
            ->all();
    }
}
