<?php

namespace App\Support;

use App\Mail;
use App\Models;
use App\Notifications as N;
use Illuminate\Support\Facades\DB;

/**
 * Renders every e-mail the backend sends (mailables + the mail channel of
 * notifications) with the latest real rows as sample data, and lists the
 * links in each. Used by tests/Feature/EmailLinksTest (fixtures created first)
 * and php artisan mail:links (production: read-only, everything rolled back).
 *
 * Rule: page links go to the storefront (frontend_url) or the admin panel
 * (admin_url), never to the API domain. The only API links allowed are
 * endpoints, not pages: ALLOWED_API_PATHS.
 */
final class EmailLinkAudit
{
    /**
     * Backend endpoints an e-mail may link to:
     *   /api/ads/r/{token}    sponsored-product click tracking, 302 to the product page
     *   /storage/…            images (src, not pages)
     * (Unsubscribe: the body links to the storefront page; only the List-Unsubscribe
     * header, not an href, points at the backend one-click endpoint.)
     */
    public const ALLOWED_API_PATHS = ['/api/ads/r/', '/storage/'];

    /** @var array<string, \Illuminate\Database\Eloquent\Model|null> */
    private array $samples = [];

    /**
     * @return array<string, array{html: ?string, error: ?string}> e-mail name => rendered HTML or why it couldn't be
     */
    public function renderAll(): array
    {
        $out = [];
        DB::beginTransaction();   // rendering must never change data (e.g. a missing unsubscribe token)
        try {
            foreach ($this->catalog() as $name => $make) {
                try {
                    $html = $make();
                    $out[$name] = $html === null
                        ? ['html' => null, 'error' => 'no sample data']
                        : ['html' => $html, 'error' => null];
                } catch (\Throwable $e) {
                    $out[$name] = ['html' => null, 'error' => class_basename($e) . ': ' . mb_substr($e->getMessage(), 0, 200)];
                }
            }
        } finally {
            DB::rollBack();
        }
        return $out;
    }

    /** Every href (and img src when $withImages) in the HTML, decoded, unique, in order. */
    public static function links(string $html, bool $withImages = false): array
    {
        $attr = $withImages ? '(?:href|src)' : 'href';
        preg_match_all('/\s' . $attr . '\s*=\s*(["\'])(.*?)\1/is', $html, $m);
        $links = array_map(fn ($u) => html_entity_decode(trim($u), ENT_QUOTES | ENT_HTML5), $m[2]);
        return array_values(array_unique(array_filter($links, fn ($u) => $u !== '')));
    }

    /** Why a link breaks the rule, or null when it's fine. */
    public static function problem(string $link): ?string
    {
        if ($link === '#' || str_starts_with($link, 'javascript:')) {
            return 'dead link';
        }
        if (preg_match('#^(mailto|tel):#i', $link)) {
            return null;
        }
        if (!preg_match('#^https?://#i', $link)) {
            return 'relative link (does not work in a mail client)';
        }
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        $path = (string) parse_url($link, PHP_URL_PATH);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return 'local address';
        }
        $api = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $isApi = $host === 'api.choosetounsi.tn' || ($api !== '' && $host === $api
            && $api !== strtolower((string) parse_url((string) config('app.frontend_url'), PHP_URL_HOST)));
        if ($isApi) {
            foreach (self::ALLOWED_API_PATHS as $allowed) {
                if (str_starts_with($path, $allowed)) return null;
            }
            return 'API domain (pages live on the storefront / admin panel)';
        }
        return null;
    }

    // ── Catalog ───────────────────────────────────────────────────────────────

    /** @return array<string, callable(): ?string> */
    private function catalog(): array
    {
        $seller = fn () => $this->latest('seller', fn () => Models\User::where('role', 'seller')->whereHas('sellerApplication', fn ($q) => $q->where('status', 'approved')));
        $buyer  = fn () => $this->latest('buyer', fn () => Models\User::where('role', 'client'));
        $admin  = fn () => $this->latest('admin', fn () => Models\User::where('role', 'admin'));
        $app    = fn () => $this->latest('application', fn () => Models\SellerApplication::query());
        $order  = fn () => $this->latest('order', fn () => Models\Order::whereHas('sellerOrders'));
        $so     = fn () => $this->latest('seller_order', fn () => Models\SellerOrder::whereHas('items'));
        $compl  = fn () => $this->latest('complaint', fn () => Models\Complaint::whereNotNull('order_id'));
        $camp   = fn () => $this->latest('sponsorship', fn () => Models\Sponsorship::whereNotNull('seller_id'));
        $payreq = fn () => $this->latest('payment_request', fn () => Models\PaymentRequest::query());
        $coupon = fn () => $this->latest('coupon', fn () => Models\Coupon::query());
        $prod   = fn () => $this->latest('product', fn () => Models\Product::whereNotNull('slug'));
        $refund = fn () => $this->latest('refund_task', fn () => Models\RefundDeliveryTask::whereHas('complaint.order'));
        $reply  = fn () => $this->latest('review_reply', fn () => Models\ReviewReply::query());
        $change = fn () => $this->latest('address_change', fn () => Models\SellerAddressChange::whereNotNull('user_id'));

        $mailable = fn (callable $make) => function () use ($make) {
            $m = $make();
            return $m ? (string) $m->render() : null;
        };
        // Notification e-mail as its recipient would get it
        $notif = fn (callable $make, callable $to) => function () use ($make, $to) {
            $n = $make();
            $user = $to();
            return $n && $user ? (string) $n->toMail($user)->render() : null;
        };
        // Same link as MarketingConsent::unsubscribeUrl(), with a sample token (nothing written)
        $unsubscribe = fn () => frontend_url('/unsubscribe/' . str_repeat('x', 40));

        $sampleItem = function () use ($prod) {
            $p = $prod();
            return $p ? [
                'id' => $p->id, 'name' => $p->name, 'image' => null, 'price' => 40.0, 'original' => null,
                'free_delivery' => false, 'copy' => null, 'sponsored' => false,
                'url' => frontend_url("/products/{$p->slug}?utm_source=email&utm_medium=digest"),
            ] : null;
        };

        $list = [
            // ── Mailables ──
            'MailTestMail'                    => $mailable(fn () => new Mail\MailTestMail(now()->toIso8601String())),
            'IntigoAlertMail'                 => $mailable(fn () => new Mail\IntigoAlertMail('Intigo rejected a parcel', 'Rejected by Intigo: Ville inconnue', 'ORD-TEST1234', ($o = $order()) ? $o->id : 1, 1)),
            'ContactMessageMail'              => $mailable(fn () => new Mail\ContactMessageMail([
                'name' => 'Visiteur Test', 'email' => 'visitor@example.com', 'phone' => '+216 22 123 456', 'topic' => 'order',
                'message' => 'Bonjour, une question sur ma commande.', 'locale' => 'fr', 'user_id' => null, 'ip' => '127.0.0.1',
            ])),
            'PasswordResetMail'               => $mailable(fn () => ($u = $buyer()) ? new Mail\PasswordResetMail($u, str_repeat('t', 64)) : null),
            'VerificationCodeMail'            => $mailable(fn () => ($u = $buyer()) ? new Mail\VerificationCodeMail($u, '123456') : null),
            'WelcomeUserMail'                 => $mailable(fn () => ($u = $buyer()) ? new Mail\WelcomeUserMail($u, ($p = $prod()) ? [['name' => $p->name, 'slug' => $p->slug, 'price' => 40, 'image' => null]] : []) : null),
            'SellerApplicationSubmittedMail'  => $mailable(fn () => ($u = $seller()) && ($a = $app()) ? new Mail\SellerApplicationSubmittedMail($u, $a) : null),
            'SellerApplicationApprovedMail'   => $mailable(fn () => ($u = $seller()) && ($a = $app()) ? new Mail\SellerApplicationApprovedMail($u, $a) : null),
            'PickedForYouMail'                => $mailable(fn () => ($u = $buyer()) && ($i = $sampleItem()) ? new Mail\Marketing\PickedForYouMail($u, [$i], $unsubscribe()) : null),
            'StillLookingMail'                => $mailable(fn () => ($u = $buyer()) && ($i = $sampleItem()) ? new Mail\Marketing\StillLookingMail($u, 'Artisanat', $i, $unsubscribe()) : null),
            'TargetedCouponMail'              => $mailable(fn () => ($u = $buyer()) && ($p = $prod()) ? new Mail\Growth\TargetedCouponMail($u, [
                'code' => 'TEST10', 'discount_type' => 'percentage', 'discount_value' => 10, 'label' => '-10 %',
                'product' => $p->name, 'shop' => 'Boutique', 'expires_at' => now()->addWeek()->toDateString(),
                'url' => frontend_url("/products/{$p->slug}?utm_source=email&utm_medium=coupon"),
            ], $unsubscribe()) : null),

            // ── Buyer notifications (one template: emails.buyer.notification) ──
            'OrderPlacedNotification'         => $notif(fn () => ($o = $order()) ? new N\Buyer\OrderPlacedNotification($o) : null, $buyer),
            'OrderStatusNotification'         => $notif(fn () => ($o = $order()) ? new N\Buyer\OrderStatusNotification($o, 'shipped', $o->sellerOrders->pluck('id')->all(), null, 'Karim') : null, $buyer),
            'PaymentNotification'             => $notif(fn () => ($o = $order()) ? new N\Buyer\PaymentNotification($o, 'failed') : null, $buyer),
            'AccountSecurityNotification'     => $notif(fn () => new N\Buyer\AccountSecurityNotification('password_changed'), $buyer),
            'ComplaintNotification'           => $notif(fn () => ($c = $compl()) ? new N\Buyer\ComplaintNotification($c, 'received') : null, $buyer),
            'RefundNotification'              => $notif(fn () => ($t = $refund()) ? new N\Buyer\RefundNotification($t, $t->complaint->order, 'completed') : null, $buyer),
            'FavoriteProductNotification'     => $notif(fn () => ($p = $prod()) ? new N\Buyer\FavoriteProductNotification($p, 'back_in_stock') : null, $buyer),
            'CouponExpiringNotification'      => $notif(fn () => ($c = $coupon()) && ($p = $prod()) ? new N\Buyer\CouponExpiringNotification($c, $p->name, $p->slug, 'Boutique') : null, $buyer),
            'TargetedCouponNotification'      => $notif(fn () => ($p = $prod()) ? new N\Growth\TargetedCouponNotification('TEST10', 'percentage', 10, $p->name, $p->slug, 'Boutique', now()->addWeek()->toDateString()) : null, $buyer),
            'ReviewReplyNotification'         => $notif(fn () => ($r = $reply()) ? new N\Buyer\ReviewReplyNotification($r) : null, $buyer),
            'ReviewPromptNotification'        => $notif(fn () => ($s = $so()) ? new N\ReviewPromptNotification($s, $s->items->pluck('id')->all()) : null, $buyer),
            'SellerApplicationReviewedNotification' => $notif(fn () => new N\SellerApplicationReviewedNotification('approved', 'Boutique Test'), $seller),

            // ── Seller order e-mails ──
            'NewSellerOrderNotification'        => $notif(fn () => ($s = $so()) ? new N\Orders\NewSellerOrderNotification($s) : null, $seller),
            'SellerOrderConfirmedNotification'  => $notif(fn () => ($s = $so()) ? new N\Orders\SellerOrderConfirmedNotification($s) : null, $seller),
            'SellerOrderCancelledNotification'  => $notif(fn () => ($s = $so()) ? new N\Orders\SellerOrderCancelledNotification($s) : null, $seller),
            'SellerPickupReminderNotification'  => $notif(fn () => ($s = $so()) ? new N\Orders\SellerPickupReminderNotification($s) : null, $seller),
            // (SellerParcelStatusNotification: bell only, no e-mail)

            // ── Seller insights / ads / payments ──
            'LowStockNotification'            => $notif(fn () => ($p = $prod()) ? new N\LowStockNotification([['product_id' => $p->id, 'variant_id' => null, 'name' => $p->name, 'variant_label' => null, 'stock' => 2, 'threshold' => 5]], true) : null, $seller),
            'ForecastAlertNotification'       => $notif(fn () => ($p = $prod()) ? new N\ForecastAlertNotification('stockout', ['product_id' => $p->id, 'product' => $p->name, 'date' => '1 nov.', 'qty' => 10, 'by' => '28 oct.'], true) : null, $seller),
            'ForecastDigestNotification'      => $notif(fn () => new N\ForecastDigestNotification(['next28' => ['low' => 100, 'high' => 200, 'revenue_low' => 1000, 'revenue_high' => 2000],'stock' => ['at_risk' => []], 'events' => [], 'accuracy' => null], 'Boutique Test'), $seller),
            'GrowthRadarNotification'         => $notif(fn () => new N\Growth\GrowthRadarNotification('result', ['kind' => 'discount', 'verdict' => 'worked', 'product' => 'Produit'], true), $seller),
            'ProfitGoalNotification'          => $notif(fn () => new N\ProfitGoalNotification('milestone', ['month' => now()->format('Y-m'), 'pct' => 50, 'sales' => 1234.5, 'goal' => 2469, 'remaining' => 1234.5, 'days_left' => 12], ['bell', 'mail']), $seller),
            'CampaignActivated'               => $notif(fn () => ($c = $camp()) ? new N\Ads\CampaignActivated($c) : null, $seller),
            'CampaignBudgetAlert'             => $notif(fn () => ($c = $camp()) ? new N\Ads\CampaignBudgetAlert($c) : null, $seller),
            'CampaignPaused'                  => $notif(fn () => ($c = $camp()) ? new N\Ads\CampaignPaused($c, 'budget') : null, $seller),
            'CampaignEnded'                   => $notif(fn () => ($c = $camp()) ? new N\Ads\CampaignEnded($c, Models\Sponsorship::STATUS_COMPLETED, ['spend' => 10, 'clicks' => 5, 'orders' => 1, 'revenue' => 40, 'refund' => 0, 'roas' => 4]) : null, $seller),
            'CampaignLowPerformance'          => $notif(fn () => ($c = $camp()) ? new N\Ads\CampaignLowPerformance($c, ['low_ctr']) : null, $seller),
            'AdWalletLow'                     => $notif(fn () => ($c = $camp()) ? new N\Ads\AdWalletLow($c, 3.5, 10) : null, $seller),
            'PaymentRequestDecided'           => $notif(fn () => ($r = $payreq()) ? new N\PaymentRequestDecided($r) : null, $seller),
            'ComplaintCreatedNotification (seller)' => $notif(fn () => ($c = $compl()) && ($u = $buyer()) ? new N\ComplaintCreatedNotification($c, $u) : null, $seller),

            // ── Admin e-mails (admin panel links) ──
            'ComplaintCreatedNotification (admin)'  => $notif(fn () => ($c = $compl()) && ($u = $buyer()) ? new N\ComplaintCreatedNotification($c, $u) : null, $admin),
            'SellerRejectedComplaintNotification'   => $notif(fn () => ($c = $compl()) && ($u = $seller()) ? new N\SellerRejectedComplaintNotification($c, $u) : null, $admin),
            'ReturnAdminNotification'               => $notif(fn () => ($c = $compl()) ? new N\Returns\ReturnAdminNotification($c, 'escalated') : null, $admin),
            'SellerPickupChangedNotification'       => $notif(fn () => ($ch = $change()) && ($owner = Models\User::find($ch->user_id)) ? new N\Sellers\SellerPickupChangedNotification($owner, $ch) : null, $admin),
        ];

        return $list;
    }

    private function latest(string $key, callable $query)
    {
        if (!array_key_exists($key, $this->samples)) {
            $this->samples[$key] = $query()->latest('id')->first();
        }
        return $this->samples[$key];
    }
}
