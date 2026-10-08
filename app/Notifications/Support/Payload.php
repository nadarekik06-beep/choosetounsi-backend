<?php

namespace App\Notifications\Support;

/**
 * The one payload shape of every database notification (buyer, seller, admin):
 *
 *   type      what happened (order_shipped, low_stock, …)
 *   category  orders | payments | complaints | reviews | promotions | account
 *             (+ products | ads | insights | subscription | system for seller / admin rows)
 *   audience  buyer | seller | admin — which bell shows it
 *   title     short title
 *   body      one line
 *   link      frontend path opened on click (null: nothing to open)
 *   icon      NotificationBell icon name
 *   action    accent of the row (NotificationBell accent())
 *   data      everything else (order id, amounts, …)
 *
 * ContractDatabaseChannel runs every payload through normalize(), so a
 * notification can't store the old keys (message, action_url, url) again.
 */
final class Payload
{
    public const AUDIENCES = ['buyer', 'seller', 'admin'];

    public const KEYS = ['type', 'category', 'audience', 'title', 'body', 'link', 'icon', 'action', 'data'];

    /** Old keys, folded into the contract. */
    private const LEGACY = ['message', 'action_url', 'url'];

    /**
     * Notifications written before the contract (and the few that still don't
     * declare it): class basename => [audience|null, category]. A null audience
     * means "whoever received it": admin, seller or buyer by the user's role.
     */
    private const CLASS_DEFAULTS = [
        // Buyer
        'ComplaintStatusChangedNotification'    => ['buyer', 'complaints'],
        'RefundCompletedNotification'           => ['buyer', 'payments'],
        'ReviewPromptNotification'              => ['buyer', 'reviews'],
        'TargetedCouponNotification'            => ['buyer', 'promotions'],
        // Seller
        'NewSellerOrderNotification'            => ['seller', 'orders'],
        'SellerOrderConfirmedNotification'      => ['seller', 'orders'],
        'SellerOrderCancelledNotification'      => ['seller', 'orders'],
        'SellerPickupReminderNotification'      => ['seller', 'orders'],
        'OrderConfirmedNotification'            => ['seller', 'orders'],
        'RefundStatusNotification'              => ['seller', 'payments'],
        'LowStockNotification'                  => ['seller', 'products'],
        'OutOfStockNotification'                => ['seller', 'products'],
        'ProductReviewedNotification'           => ['seller', 'products'],
        'CampaignActivated'                     => ['seller', 'ads'],
        'CampaignBudgetAlert'                   => ['seller', 'ads'],
        'CampaignEnded'                         => ['seller', 'ads'],
        'CampaignLowPerformance'                => ['seller', 'ads'],
        'CampaignPaused'                        => ['seller', 'ads'],
        'AdWalletLow'                           => ['seller', 'ads'],
        'PaymentRequestDecided'                 => ['seller', 'subscription'],
        'SubscriptionUpdatedNotification'       => ['seller', 'subscription'],
        'BlackSmartNotification'                => ['seller', 'insights'],
        'ForecastAlertNotification'             => ['seller', 'insights'],
        'ForecastDigestNotification'            => ['seller', 'insights'],
        'GrowthRadarNotification'               => ['seller', 'insights'],
        'ProfitGoalNotification'                => ['seller', 'insights'],
        'PackApprovedNotification'              => ['seller', 'products'],
        // Admin
        'NewSellerApplicationNotification'      => ['admin', 'account'],
        'ProductChangedNotification'            => ['admin', 'products'],
        'SellerUpgradedNotification'            => ['admin', 'subscription'],
        'SellerRejectedComplaintNotification'   => ['admin', 'complaints'],
        'VipRequestSubmittedNotification'       => ['admin', 'subscription'],
        'ReturnAdminNotification'               => ['admin', 'complaints'],
        'ReturnSellerNotification'              => ['seller', 'complaints'],
        // Sent to several audiences: the recipient's role decides
        'ComplaintCreatedNotification'          => [null, 'complaints'],
        'ProductActionNotification'             => [null, 'products'],
        'SellerApplicationReviewedNotification' => [null, 'account'],
    ];

    /**
     * @param array       $raw               what the notification's toDatabase()/toArray() returned
     * @param string|null $notificationClass the notification's class (notifications.type)
     * @param mixed       $notifiable        the recipient (a User, an Admin…)
     * @param array       $declared          ['audience' => …, 'category' => …] declared by the notification
     */
    public static function normalize(array $raw, ?string $notificationClass, $notifiable, array $declared = []): array
    {
        [$classAudience, $classCategory] = self::classDefaults($notificationClass);

        $audience = $declared['audience'] ?? $raw['audience'] ?? $classAudience ?? self::audienceOf($notifiable);
        if (!in_array($audience, self::AUDIENCES, true)) {
            $audience = self::audienceOf($notifiable);
        }

        $extras = array_diff_key($raw, array_flip(array_merge(self::KEYS, self::LEGACY)));
        $data   = array_merge(is_array($raw['data'] ?? null) ? $raw['data'] : [], $extras);

        return [
            'type'     => (string) ($raw['type'] ?? self::snake($notificationClass)),
            'category' => (string) ($declared['category'] ?? $raw['category'] ?? $classCategory ?? 'system'),
            'audience' => $audience,
            'title'    => (string) ($raw['title'] ?? ''),
            'body'     => (string) ($raw['body'] ?? $raw['message'] ?? ''),
            'link'     => self::path($raw['link'] ?? $raw['action_url'] ?? $raw['url'] ?? null),
            'icon'     => (string) ($raw['icon'] ?? 'bell'),
            'action'   => (string) ($raw['action'] ?? 'info'),
            'data'     => $data,
        ];
    }

    /** True when a stored payload already follows the contract. */
    public static function isNormalized(array $raw): bool
    {
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $raw)) return false;
        }
        return count(array_diff_key($raw, array_flip(self::KEYS))) === 0;
    }

    public static function audienceOf($notifiable): string
    {
        $role = is_object($notifiable) ? ($notifiable->role ?? null) : null;
        if ($notifiable instanceof \App\Models\Admin || $role === 'admin') return 'admin';
        return $role === 'seller' ? 'seller' : 'buyer';
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function classDefaults(?string $class): array
    {
        $base = $class ? class_basename($class) : null;
        return $base && isset(self::CLASS_DEFAULTS[$base]) ? self::CLASS_DEFAULTS[$base] : [null, null];
    }

    /** Links stored by older payloads that point to pages that don't exist. */
    private const DEAD_LINKS = [
        '/seller/dashboard' => '/seller',
        '/apply-seller'     => '/become-a-vendor',
    ];

    /** Frontend path: absolute links to the storefront become relative, '' becomes null. */
    private static function path($link): ?string
    {
        if (!is_string($link) || trim($link) === '') return null;

        $front = rtrim((string) config('app.frontend_url'), '/');
        if ($front !== '' && str_starts_with($link, $front)) {
            $link = substr($link, strlen($front)) ?: '/';
        }
        return self::DEAD_LINKS[$link] ?? $link;
    }

    private static function snake(?string $class): string
    {
        if (!$class) return 'general';
        return \Illuminate\Support\Str::snake(preg_replace('/Notification$/', '', class_basename($class)));
    }
}
