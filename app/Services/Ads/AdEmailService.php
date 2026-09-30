<?php

namespace App\Services\Ads;

use App\Mail\Marketing\PickedForYouMail;
use App\Mail\Marketing\StillLookingMail;
use App\Models\SponsorshipEvent;
use App\Models\User;
use App\Services\Recommendation\HomeFeedBuilder;
use App\Services\Recommendation\InterestProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Consent-based marketing e-mails (ads.digest_* / ads.interest_emails_enabled):
 *
 *   weekly "Picked for you"  organic recommendations + up to ads.max_ads.email_digest sponsored
 *                            products (placement email_digest), for opted-in users with a profile
 *   "Still looking for X?"   a subcategory viewed ≥ 3 times in 7 days without buying, and a
 *                            sponsored product there that is discounted or ships free
 *
 * At most one marketing e-mail per user per ads.marketing_email_gap_days (both kinds count).
 * Sponsored links go through GET /api/ads/r/{token}: the seller pays only if it's clicked.
 * Sending costs sellers nothing; the impression is recorded when the e-mail is sent.
 */
class AdEmailService
{
    const MIN_ITEMS = 3;
    const INTEREST_MIN_VIEWS = 3;
    const INTEREST_DAYS = 7;

    public function __construct(
        private AdSettings $settings,
        private AdServer $ads,
        private AdEventService $events,
        private MarketingConsent $consent,
        private InterestProfileService $profiles,
    ) {}

    public function eligibleUsers()
    {
        return $this->consent->eligibleQuery(max(1, $this->settings->int('marketing_email_gap_days')));
    }

    public function sendDigest(User $user): bool
    {
        if (!$this->canMail($user) || ($this->profiles->forActor($user->id, null)['is_cold'] ?? true)) {
            return false;
        }

        $total   = max(self::MIN_ITEMS, $this->settings->int('digest_products'));
        $organic = $this->organicPicks($user, $total);
        $served  = $this->ads->serve(new AdRequest(
            'email_digest', $user->id, null, (int) $this->settings->get('max_ads.email_digest', 2),
            excludeProductIds: array_column($organic, 'id'), forEmail: true,
        ))['ads'];

        $ads   = array_map(fn ($ad) => $this->item($ad, true), $served);
        $items = array_merge(array_slice($ads, 0, 1), array_slice(array_map(fn ($c) => $this->item($c, false), $organic), 0, $total - count($ads)), array_slice($ads, 1));
        if (count($items) < self::MIN_ITEMS) {
            return false;
        }

        $this->recordImpressions($user, $served);
        Mail::to($user)->queue(new PickedForYouMail($user, $items, $this->consent->unsubscribeUrl($user)));
        $this->markSent($user);
        return true;
    }

    public function sendInterest(User $user): bool
    {
        if (!$this->canMail($user)) {
            return false;
        }
        $interest = $this->recentInterest($user->id);
        if (!$interest) {
            return false;
        }

        $deals = $this->dealProductIds((int) $interest->subcategory_id);
        if (!$deals) {
            return false;
        }
        $served = $this->ads->serve(new AdRequest('email_digest', $user->id, null, 1, forEmail: true, onlyProductIds: $deals))['ads'];
        if (!$served) {
            return false;
        }

        $this->recordImpressions($user, $served);
        $category = (string) (DB::table('subcategories')->where('id', $interest->subcategory_id)->value($this->nameColumn($user)) ?: DB::table('subcategories')->where('id', $interest->subcategory_id)->value('name'));
        Mail::to($user)->queue(new StillLookingMail($user, $category, $this->item($served[0], true), $this->consent->unsubscribeUrl($user)));
        $this->markSent($user);
        return true;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function canMail(User $user): bool
    {
        $gap = max(1, $this->settings->int('marketing_email_gap_days'));
        return $user->marketing_emails_opt_in && $user->is_active && $user->email_verified_at && $user->email
            && (!$user->last_marketing_email_at || $user->last_marketing_email_at->lte(now()->subDays($gap)));
    }

    /** Recommended row of the user's homepage feed (trending as a fallback), as product cards. */
    private function organicPicks(User $user, int $n): array
    {
        try {
            $feed = app(HomeFeedBuilder::class)->build($user->id, null);
        } catch (\Throwable $e) {
            Log::warning('[AdEmailService] feed failed: ' . $e->getMessage());
            return [];
        }
        $sections = collect($feed['sections'] ?? [])->keyBy('key');
        $cards = [];
        foreach (['recommended', 'similar', 'trending'] as $key) {
            foreach ($sections[$key]['products'] ?? [] as $card) {
                if (empty($card['is_sponsored']) && !isset($cards[$card['id']])) {
                    $cards[$card['id']] = $card;
                }
            }
        }
        return array_slice(array_values($cards), 0, $n);
    }

    /** The subcategory viewed ≥ 3 times in the last 7 days without a purchase in it. */
    private function recentInterest(int $userId): ?object
    {
        $since  = now()->subDays(self::INTEREST_DAYS);
        $bought = DB::table('user_interactions as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.user_id', $userId)->where('i.event_type', 'purchase')->where('i.created_at', '>=', $since)
            ->whereNotNull('p.subcategory_id')->pluck('p.subcategory_id')->all();

        return DB::table('user_interactions as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.user_id', $userId)->where('i.event_type', 'view')->where('i.created_at', '>=', $since)
            ->whereNotNull('p.subcategory_id')->whereNotIn('p.subcategory_id', $bought ?: [0])
            ->groupBy('p.subcategory_id')->havingRaw('COUNT(*) >= ?', [self::INTEREST_MIN_VIEWS])
            ->orderByRaw('COUNT(*) DESC')->first(['p.subcategory_id', DB::raw('COUNT(*) AS views')]);
    }

    /** Available products in the subcategory that are discounted right now or ship free. */
    private function dealProductIds(int $subcategoryId): array
    {
        $promoted = DB::table('promotion_products as pp')->join('promotions as pr', 'pr.id', '=', 'pp.promotion_id')
            ->where('pr.status', 'active')->where('pr.starts_at', '<=', now())->where('pr.ends_at', '>', now())
            ->pluck('pp.product_id')->all();

        return DB::table('products')->where('subcategory_id', $subcategoryId)
            ->where('is_approved', true)->where('is_active', true)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('id', $promoted ?: [0])->orWhere('delivery_fee', 0))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function recordImpressions(User $user, array $served): void
    {
        foreach ($served as $ad) {
            $this->events->record($ad['ad_token'], SponsorshipEvent::IMPRESSION,
                ['user_id' => $user->id, 'session_id' => null, 'ip' => null, 'user_agent' => 'email']);
        }
    }

    /** Normalized e-mail item: absolute image/link URLs, promo price, ad flag. */
    private function item(array $card, bool $sponsored): array
    {
        $front = rtrim((string) config('app.frontend_url'), '/');
        $img   = $card['primary_image_url'] ?? null;
        $price = (float) ($card['effective_price'] ?? $card['price']);
        $orig  = isset($card['original_price']) && (float) $card['original_price'] > $price + 0.001 ? (float) $card['original_price'] : null;

        return [
            'id'            => (int) $card['id'],
            'name'          => (string) $card['name'],
            'image'         => $img ? (str_starts_with($img, 'http') ? $img : url($img)) : null,
            'price'         => $price,
            'original'      => $orig,
            'free_delivery' => isset($card['delivery_fee']) && $card['delivery_fee'] !== null && (float) $card['delivery_fee'] === 0.0,
            'copy'          => $sponsored ? ($card['sponsor_data']['ai_ad_copy'] ?? null) : null,
            'sponsored'     => $sponsored,
            'url'           => $sponsored
                ? route('ads.redirect', ['token' => $card['ad_token']])
                : "{$front}/products/{$card['slug']}?utm_source=email&utm_medium=digest",
        ];
    }

    private function markSent(User $user): void
    {
        $user->forceFill(['last_marketing_email_at' => now()])->save();
    }

    private function nameColumn(User $user): string
    {
        return match ($user->locale) {
            'fr' => 'name_fr',
            'ar' => 'name_ar',
            default => 'name',
        };
    }
}
