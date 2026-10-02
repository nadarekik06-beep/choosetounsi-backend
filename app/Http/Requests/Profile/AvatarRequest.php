<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/profile/avatar — re-encoded server side, see ProfileApiController::uploadAvatar. */
class AvatarRequest extends FormRequest
{
    public const MAX_KB = 3072;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:' . self::MAX_KB, 'dimensions:min_width=64,min_height=64,max_width=6000,max_height=6000'],
        ];
    }

    public function attributes(): array
    {
        return ['avatar' => __('messages.profile.fields.avatar')];
    }
}
