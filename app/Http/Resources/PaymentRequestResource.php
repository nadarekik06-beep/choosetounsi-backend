<?php

namespace App\Http\Resources;

use App\Models\PaymentRequest;
use App\Models\SubscriptionPlan;
use App\Services\Payments\PaymentRequestService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A manual payment request. Sellers get the WhatsApp link to (re)open their
 * pre-filled message; admins (->forAdmin()) also get the seller's details,
 * the link to chat with the seller and the action log.
 */
class PaymentRequestResource extends JsonResource
{
    private bool $admin = false;

    public function forAdmin(): static
    {
        $this->admin = true;
        return $this;
    }

    public function toArray($request): array
    {
        /** @var PaymentRequest $r */
        $r = $this->resource;
        $service = app(PaymentRequestService::class);
        $plan = fn (?string $slug) => $slug ? ['slug' => $slug, 'name' => SubscriptionPlan::forSlug($slug)->name] : null;

        $out = [
            'id'                    => $r->id,
            'reference'             => $r->reference,
            'type'                  => $r->type,
            'source'                => $r->source,
            'status'                => $r->status,
            'amount'                => round((float) $r->amount, 3),
            'amount_received'       => $r->amount_received !== null ? round((float) $r->amount_received, 3) : null,
            'current_plan'          => $plan($r->current_plan),
            'requested_plan'        => $plan($r->requested_plan),
            'billing_period'        => $r->billing_period,
            'payment_method'        => $r->payment_method,
            'rejection_reason'      => $r->rejection_reason,
            'message'               => $r->message,
            'whatsapp_url'          => $r->isPending() ? $service->whatsappUrl($r) : null,
            'decided_at'            => $r->decided_at?->toIso8601String(),
            'cancelled_at'          => $r->cancelled_at?->toIso8601String(),
            'created_at'            => $r->created_at?->toIso8601String(),
        ];

        if (!$this->admin) {
            return $out;
        }

        return $out + [
            'seller' => [
                'id'             => $r->seller_id,
                'name'           => $r->seller_name,
                'store_name'     => $r->store_name,
                'email'          => $r->seller_email,
                'phone'          => $r->seller_phone,
                'wallet_balance' => $r->wallet_balance !== null ? round((float) $r->wallet_balance, 3) : null,
            ],
            'contact_url'           => $service->sellerContactUrl($r),
            'transaction_reference' => $r->transaction_reference,
            'admin_note'            => $r->admin_note,
            'decided_by'            => $r->relationLoaded('decidedBy') && $r->decidedBy ? ['id' => $r->decidedBy->id, 'name' => $r->decidedBy->name] : null,
            'ad_top_up_id'          => $r->ad_top_up_id,
            'subscription_payment_id' => $r->subscription_payment_id,
            'logs'                  => $this->whenLoaded('logs', fn () => $r->logs->map(fn ($l) => [
                'action'     => $l->action,
                'actor'      => $l->actor ? ['id' => $l->actor->id, 'name' => $l->actor->name] : null,
                'actor_role' => $l->actor_role,
                'data'       => $l->data,
                'created_at' => $l->created_at?->toIso8601String(),
            ])),
        ];
    }
}
