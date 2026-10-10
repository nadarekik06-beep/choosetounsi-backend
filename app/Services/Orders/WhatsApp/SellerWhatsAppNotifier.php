<?php

namespace App\Services\Orders\WhatsApp;

use App\Models\SellerOrder;
use App\Models\User;
use App\Support\TunisianPhone;

/**
 * The "send" step of seller WhatsApp notifications — the only class to
 * change for the official WhatsApp Cloud API.
 *
 * Today (manual, free): compose() gives the admin panel a wa.me link with the
 * message pre-filled; the admin sends it from the business WhatsApp, then
 * send() records it. With the Cloud API, send() would post the message to
 * Meta first (same compose() text / number) and the admin click — or the
 * scheduler — would be the only trigger.
 */
class SellerWhatsAppNotifier
{
    public function __construct(
        private SellerWhatsAppMessages $messages,
        private SellerReminderService  $reminders,
    ) {}

    /**
     * @return array{phone:?string, phone_source:?string, text:string, url:?string}
     *         phone_source: whatsapp (WhatsApp number) | phone (account / shop phone fallback)
     */
    public function compose(SellerOrder $parcel, int $attempt): array
    {
        [$phone, $source] = self::recipient($parcel->seller);
        $text = $this->messages->build($parcel, $attempt);

        return [
            'phone'        => $phone,
            'phone_source' => $source,
            'text'         => $text,
            'url'          => $phone ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($text) : null,
        ];
    }

    /**
     * The message went out (manual mode: the admin sent it from the wa.me
     * link). Records it and schedules what comes next.
     *
     * @throws \DomainException when the parcel no longer needs it
     */
    public function send(SellerOrder $parcel, int $attempt, User $admin): void
    {
        $this->reminders->markSent($parcel, $attempt, $admin);
    }

    /**
     * WhatsApp number (international, no "+"): the seller's WhatsApp number,
     * else their account phone, else their shop phone.
     *
     * @return array{0:?string, 1:?string} [number, whatsapp|phone|null]
     */
    public static function recipient(?User $seller): array
    {
        if (!$seller) {
            return [null, null];
        }
        if ($n = TunisianPhone::toInternational($seller->whatsapp_number)) {
            return [$n, 'whatsapp'];
        }
        $n = self::fallbackPhone($seller);
        return [$n, $n ? 'phone' : null];
    }

    /** Without a WhatsApp number: the account phone, else the shop phone (international, no "+"). */
    public static function fallbackPhone(User $seller): ?string
    {
        $shopPhone = $seller->relationLoaded('sellerApplication') ? $seller->sellerApplication?->phone_number : $seller->sellerApplication()->value('phone_number');
        foreach ([$seller->phone, $shopPhone] as $phone) {
            if ($n = TunisianPhone::toInternational($phone)) {
                return $n;
            }
        }
        return null;
    }
}
