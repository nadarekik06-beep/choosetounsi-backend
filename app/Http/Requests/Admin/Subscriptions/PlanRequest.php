<?php

namespace App\Http\Requests\Admin\Subscriptions;

use App\Models\SubscriptionPlan;
use Illuminate\Validation\Rule;

/** Create (POST) or update (PUT) a subscription plan. */
class PlanRequest extends AdminRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req      = $creating ? 'required' : 'sometimes';

        return [
            // slug is immutable once created (stored on subscriptions and orders)
            'slug'                   => $creating
                ? ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/', Rule::unique('subscription_plans', 'slug')]
                : ['prohibited'],
            'name'                   => [$req, 'string', 'max:80'],
            'description'            => ['nullable', 'string', 'max:1000'],
            'badge_color'            => [$req, 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'display_order'          => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'tier'                   => [$req, 'integer', Rule::in([0, 1, 2])],
            'price_monthly'          => [$req, 'numeric', 'min:0', 'max:100000'],
            'price_yearly'           => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'trial_days'             => ['sometimes', 'integer', 'min:0', 'max:90'],
            'commission_rate'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commission_reduction'   => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'max_products'           => ['nullable', 'integer', 'min:1'],
            'max_images_per_product' => ['nullable', 'integer', 'min:1', 'max:100'],
            'max_sponsored_products' => ['nullable', 'integer', 'min:0'],
            'features'               => [$creating ? 'present' : 'sometimes', 'array'],
            'features.*'             => ['boolean'],
            'is_active'              => ['sometimes', 'boolean'],
            'reason'                 => $this->reasonRule(false),
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex'      => 'The slug may only contain lowercase letters, numbers, dashes and underscores.',
            'slug.prohibited' => 'A plan slug cannot be changed after creation.',
        ];
    }

    /** Only known feature keys are stored. */
    public function features(): ?array
    {
        if (!$this->has('features')) return null;
        $in = (array) $this->input('features');
        return collect(SubscriptionPlan::FEATURES)
            ->mapWithKeys(fn($label, $key) => [$key => (bool) ($in[$key] ?? false)])
            ->all();
    }
}
