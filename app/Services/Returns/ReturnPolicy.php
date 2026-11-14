<?php

namespace App\Services\Returns;

use App\Models\Category;
use App\Models\Complaint;
use App\Models\OrderItem;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\Subcategory;
use Illuminate\Support\Collection;

/**
 * The rules of the exchange-first returns policy (v2), in one place. The storefront
 * never decides anything: it renders what optionsForLines() returns.
 *
 *   - platform setting `returns.policy` = { v2_enabled, receive_timeout_days, inspection_days }
 *     (off by default: everything behaves as before until the admin switches it on);
 *   - the 48 h window counts from the real delivered date of each parcel
 *     (seller_orders.delivery_confirmed_at, set by the Intigo webhook);
 *   - reasons decide what is allowed (exchange first, refund when allowed);
 *   - "changed my mind" can be switched off per category / subcategory.
 */
class ReturnPolicy
{
    public const KEY = 'returns.policy';
    public const DEFAULTS = ['v2_enabled' => false, 'receive_timeout_days' => 3, 'inspection_days' => 3];

    /** Reasons offered by the v2 form (complaint_type values that already exist). */
    public const REASONS = ['doesnt_fit', 'damaged_product', 'wrong_product', 'changed_mind'];
    public const MIN_PHOTOS = 2;
    public const MAX_PHOTOS = 3;

    // ── Settings ────────────────────────────────────────────────────────────

    public function settings(): array
    {
        $saved = (array) (PlatformSetting::getValue(self::KEY) ?? []);
        return [
            'v2_enabled'           => (bool) ($saved['v2_enabled'] ?? self::DEFAULTS['v2_enabled']),
            'receive_timeout_days' => max(1, (int) ($saved['receive_timeout_days'] ?? self::DEFAULTS['receive_timeout_days'])),
            'inspection_days'      => max(1, (int) ($saved['inspection_days'] ?? self::DEFAULTS['inspection_days'])),
        ];
    }

    public function enabled(): bool
    {
        return $this->settings()['v2_enabled'];
    }

    public function update(array $values, ?int $adminId): array
    {
        $next = array_merge($this->settings(), array_intersect_key($values, self::DEFAULTS));
        PlatformSetting::setValue(self::KEY, [
            'v2_enabled'           => (bool) $next['v2_enabled'],
            'receive_timeout_days' => max(1, (int) $next['receive_timeout_days']),
            'inspection_days'      => max(1, (int) $next['inspection_days']),
        ], $adminId);
        PlatformSetting::flushCache();
        return $this->settings();
    }

    // ── Window ──────────────────────────────────────────────────────────────

    /** The parcel's real delivered date (Intigo webhook), else its last update (rows without it). */
    public function deliveredAt(SellerOrder $parcel): ?\Illuminate\Support\Carbon
    {
        $at = $parcel->getAttribute('delivery_confirmed_at');
        return $at ? \Illuminate\Support\Carbon::parse($at) : $parcel->updated_at;
    }

    public function windowEndsAt(SellerOrder $parcel): ?\Illuminate\Support\Carbon
    {
        return $this->deliveredAt($parcel)?->copy()->addHours(Complaint::COMPLAINT_WINDOW_HOURS);
    }

    public function windowOpen(SellerOrder $parcel): bool
    {
        $end = $this->windowEndsAt($parcel);
        return $end !== null && $end->isFuture();
    }

    // ── Categories ──────────────────────────────────────────────────────────

    /** Subcategory flag when set (0 = explicit exception), else the category's. */
    public function categoryAllowsChangedMind(?Product $product): bool
    {
        if (!$product) {
            return true;
        }
        if ($product->subcategory_id) {
            $sub = Subcategory::query()->whereKey($product->subcategory_id)->value('no_changed_mind_return');
            if ($sub !== null) {
                return !(bool) $sub;
            }
        }
        if ($product->category_id) {
            return !(bool) Category::query()->whereKey($product->category_id)->value('no_changed_mind_return');
        }
        return true;
    }

    /** Product page line: "exchange_easy" or "defect_only". */
    public function returnsMode(?Product $product): string
    {
        return $this->categoryAllowsChangedMind($product) ? 'exchange_easy' : 'defect_only';
    }

    // ── Exchange availability ───────────────────────────────────────────────

    /**
     * What this line can be exchanged for: in-stock variants of the same product at
     * the same price (the current one included, for a defect). Products without
     * variants: the same product again, when in stock.
     *
     * @return array{exchange_options: array, exchange_same_product: bool, exchange_other_price: bool}
     */
    public function exchangeOptions(OrderItem $line): array
    {
        $product = $line->product;
        if (!$product || $product->trashed() || !$product->is_active) {
            return ['exchange_options' => [], 'exchange_same_product' => false, 'exchange_other_price' => false];
        }
        $variants = $product->variants->where('is_active', true);
        if ($variants->isEmpty()) {
            return ['exchange_options' => [], 'exchange_same_product' => (int) $product->stock > 0, 'exchange_other_price' => false];
        }
        $original = $line->variant_id ? $product->variants->firstWhere('id', (int) $line->variant_id) : null;
        $price    = $original ? $original->effective_price : (float) $line->unit_price;

        $options = $variants
            ->filter(fn($v) => (int) $v->stock > 0 && abs($v->effective_price - $price) < 0.0005)
            ->map(fn($v) => ['id' => (int) $v->id, 'label' => $v->label ?: ('#' . $v->id), 'stock' => (int) $v->stock, 'current' => (int) $v->id === (int) $line->variant_id])
            ->values()->all();
        $otherPrice = $variants->contains(fn($v) => (int) $v->stock > 0 && abs($v->effective_price - $price) >= 0.0005);

        return ['exchange_options' => $options, 'exchange_same_product' => false, 'exchange_other_price' => $otherPrice];
    }

    /** No valid replacement the form could offer (zero stock, or only other-price variants). */
    public function exchangeImpossible(OrderItem $line): bool
    {
        $o = $this->exchangeOptions($line);
        return empty($o['exchange_options']) && !$o['exchange_same_product'];
    }

    // ── Options per reason ──────────────────────────────────────────────────

    /**
     * Who pays the after-sale parcel for a request. Seller fault: defective, wrong
     * item, and a size problem whose exchange is impossible (the stock-out is the
     * shop's). Otherwise the client.
     */
    public function payerFor(string $type, string $resolution): string
    {
        if ($type === 'doesnt_fit' && $resolution === Complaint::RESOLUTION_RETURN_REFUND) {
            return 'seller';
        }
        return Complaint::payerFor($type);
    }

    /**
     * What may be asked for these lines, per reason, with the why.
     *
     * @param  Collection<int, OrderItem> $lines (with product + variants loaded)
     * @return array<string, array{exchange_allowed: bool, refund_allowed: bool, shipping_payer: string,
     *                             unused_required: bool, disabled: bool, disabled_reason: ?string, refund_note: ?string}>
     */
    public function optionsForLines(Collection $lines): array
    {
        $impossible = $lines->isNotEmpty() && $lines->every(fn(OrderItem $l) => $this->exchangeImpossible($l));
        $anyImpossible = $lines->contains(fn(OrderItem $l) => $this->exchangeImpossible($l));
        $excluded = $lines->first(fn(OrderItem $l) => !$this->categoryAllowsChangedMind($l->product));

        $out = [];
        foreach (self::REASONS as $reason) {
            $exchange = !$anyImpossible;
            $refund = false;
            $disabled = null;
            $note = null;
            $unused = false;

            switch ($reason) {
                case 'damaged_product':
                case 'wrong_product':
                    $refund = true;
                    break;
                case 'doesnt_fit':
                    $refund = $impossible;
                    $note = $refund ? __('returns_policy.refund_exchange_impossible') : __('returns_policy.refund_only_if_exchange_impossible');
                    break;
                case 'changed_mind':
                    if ($excluded) {
                        $disabled = __('returns_policy.changed_mind_excluded', ['item' => $excluded->product_name]);
                        $exchange = $refund = false;
                        break;
                    }
                    $refund = true;
                    $unused = true;
                    break;
            }
            if (!$exchange && !$refund && !$disabled) {
                $disabled = __('returns_policy.nothing_available');
            }
            $out[$reason] = [
                'exchange_allowed' => $exchange,
                'refund_allowed'   => $refund,
                'shipping_payer'   => $this->payerFor($reason, $refund && !$exchange ? Complaint::RESOLUTION_RETURN_REFUND : Complaint::RESOLUTION_EXCHANGE),
                'unused_required'  => $unused,
                'disabled'         => $disabled !== null,
                'disabled_reason'  => $disabled,
                'refund_note'      => $note,
                'exchange_impossible' => $anyImpossible,
            ];
        }
        return $out;
    }

    /**
     * Server-side gate for a v2 request. Throws ReturnException with the reason the
     * client can read.
     *
     * @param Collection<int, OrderItem> $lines
     */
    public function assertAllowed(Collection $lines, string $type, string $resolution, ?bool $unusedConfirmed): void
    {
        if (!in_array($type, self::REASONS, true)) {
            throw new ReturnException(__('returns_policy.reason_unknown'));
        }
        $opt = $this->optionsForLines($lines)[$type];
        if ($opt['disabled']) {
            throw new ReturnException($opt['disabled_reason']);
        }
        if ($resolution === Complaint::RESOLUTION_EXCHANGE && !$opt['exchange_allowed']) {
            throw new ReturnException(__('returns_policy.exchange_not_possible'));
        }
        if ($resolution === Complaint::RESOLUTION_RETURN_REFUND && !$opt['refund_allowed']) {
            throw new ReturnException($opt['refund_note'] ?: __('returns_policy.refund_not_allowed'));
        }
        if ($opt['unused_required'] && !$unusedConfirmed) {
            throw new ReturnException(__('returns_policy.unused_required'));
        }
    }
}
