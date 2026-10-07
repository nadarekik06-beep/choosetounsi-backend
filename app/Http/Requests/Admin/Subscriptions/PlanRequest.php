<?php

namespace App\Http\Requests\Admin\Subscriptions;

use App\Enums\PlanCapability;
use App\Models\PlanDisplayFeature;
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
            'tagline'                => ['nullable', 'string', 'max:120'],
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
            'is_recommended'         => ['sometimes', 'boolean'],

            // Pricing page: capability wording + visibility, hidden limits, display features
            'capability_display'               => ['sometimes', 'nullable', 'array'],
            'capability_display.*'             => ['array'],
            'capability_display.*.label'       => ['nullable', 'string', 'max:80'],
            'capability_display.*.description' => ['nullable', 'string', 'max:160'],
            'capability_display.*.visible'     => ['sometimes', 'boolean'],
            'hidden_limits'                    => ['sometimes', 'nullable', 'array'],
            'hidden_limits.*'                  => ['string', Rule::in(SubscriptionPlan::PUBLIC_LIMITS)],
            'display_features'                 => ['sometimes', 'array', 'max:' . PlanDisplayFeature::MAX_PER_PLAN],
            ...DisplayFeatureRequest::itemRules('display_features.*.'),
            'reason'                 => $this->reasonRule(false),
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex'      => 'The slug may only contain lowercase letters, numbers, dashes and underscores.',
            'slug.prohibited' => 'A plan slug cannot be changed after creation.',
            'display_features.max' => 'A plan can show at most ' . PlanDisplayFeature::MAX_PER_PLAN . ' features on the pricing page.',
            'display_features.*.label.required' => 'Every pricing-page feature needs a label.',
            'display_features.*.label.max'      => 'Feature labels are limited to 80 characters.',
            'display_features.*.description.max' => 'Feature descriptions are limited to 160 characters.',
        ];
    }

    /** Only known feature keys are stored. */
    public function features(): ?array
    {
        if (!$this->has('features')) return null;
        $in = (array) $this->input('features');
        return collect(PlanCapability::keys())
            ->mapWithKeys(fn($key) => [$key => (bool) ($in[$key] ?? false)])
            ->all();
    }

    /** Only known capability keys are stored; empty overrides are dropped. */
    public function capabilityDisplay(): ?array
    {
        if (!$this->has('capability_display')) return null;
        $in  = (array) $this->input('capability_display');
        $out = [];
        foreach (PlanCapability::keys() as $key) {
            $o = (array) ($in[$key] ?? []);
            $row = array_filter([
                'label'       => trim((string) ($o['label'] ?? '')) ?: null,
                'description' => trim((string) ($o['description'] ?? '')) ?: null,
            ]);
            if (array_key_exists('visible', $o) && !filter_var($o['visible'], FILTER_VALIDATE_BOOLEAN)) {
                $row['visible'] = false;
            }
            if ($row) $out[$key] = $row;
        }
        return $out ?: null;
    }

    public function hiddenLimits(): ?array
    {
        if (!$this->has('hidden_limits')) return null;
        $hidden = array_values(array_unique(array_intersect(SubscriptionPlan::PUBLIC_LIMITS, (array) $this->input('hidden_limits'))));
        return $hidden ?: [];
    }
}
