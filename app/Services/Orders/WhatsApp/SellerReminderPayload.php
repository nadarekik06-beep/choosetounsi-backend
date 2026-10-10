<?php

namespace App\Services\Orders\WhatsApp;

use App\Models\SellerOrder;
use App\Models\SellerOrderReminder;

/**
 * What the admin panel shows about a parcel's WhatsApp notices: the order
 * drawer block (forParcel) and the "WhatsApp reminders" page rows (row).
 * Eager-load seller.sellerApplication, order, items and reminders.sentBy.
 */
class SellerReminderPayload
{
    public function __construct(
        private SellerWhatsAppNotifier $notifier,
        private SellerReminderService  $reminders,
    ) {}

    /** Order drawer block, or null for CHOOSE'Tounsi's own parcels. */
    public function forParcel(SellerOrder $parcel): ?array
    {
        if ($parcel->seller_id === null || $parcel->isPlatformParcel()) {
            return null;
        }

        $needed   = $this->reminders->needsReminders($parcel);
        $history  = $parcel->relationLoaded('reminders') ? $parcel->reminders : $parcel->reminders()->with('sentBy:id,name')->get();
        $current  = $needed ? $this->current($history) : null;
        [$phone, $phoneSource] = SellerWhatsAppNotifier::recipient($parcel->seller);

        // Confirmed before reminders existed: offer the initial notice
        $attempt = $current?->attempt ?? ($needed && $history->isEmpty() ? 0 : null);

        return [
            'needs_action' => $needed,
            'prepared_at'  => $parcel->prepared_at?->toIso8601String(),
            'overdue'      => SellerReminderService::isOverdue($parcel),
            'overdue_at'   => $parcel->overdue_at?->toIso8601String(),
            'phone'        => $phone,
            'phone_source' => $phoneSource,
            'language'     => $parcel->seller?->preferred_language ?: 'fr',
            'current'      => $attempt === null ? null : [
                'attempt' => $attempt,
                'status'  => $current?->status ?? SellerOrderReminder::DUE,
                'due_at'  => $current?->due_at?->toIso8601String(),
                'url'     => $this->notifier->compose($parcel, $attempt)['url'],
            ],
            'history' => $history->map(fn (SellerOrderReminder $r) => [
                'attempt' => $r->attempt,
                'status'  => $r->status,
                'due_at'  => $r->due_at?->toIso8601String(),
                'sent_at' => $r->sent_at?->toIso8601String(),
                'sent_by' => $r->sentBy ? ['id' => $r->sentBy->id, 'name' => $r->sentBy->name] : null,
            ])->values()->all(),
        ];
    }

    /** One line of the reminders page: a due reminder, or an overdue parcel ($reminder null). */
    public function row(SellerOrder $parcel, ?SellerOrderReminder $reminder = null): array
    {
        $order  = $parcel->order;
        $seller = $parcel->seller;
        $app    = $seller?->sellerApplication;
        [$phone, $phoneSource] = SellerWhatsAppNotifier::recipient($seller);
        $last   = $parcel->relationLoaded('reminders') ? $parcel->reminders->where('status', SellerOrderReminder::SENT)->sortByDesc('attempt')->first() : null;

        return [
            'seller_order_id' => $parcel->id,
            'order'           => [
                'id'           => $order?->id,
                'order_number' => $order?->order_number,
                'confirmed_at' => ($order?->confirmed_at ? \Carbon\Carbon::parse($order->confirmed_at) : null)?->toIso8601String(),
            ],
            'seller'          => [
                'id'    => $seller?->id,
                'name'  => $app?->full_name ?: $seller?->name,
                'store' => $app?->business_name ?: $seller?->name,
                // Overdue: the admin calls — shop phone first, it's the one sellers answer
                'call_phone' => $app?->phone_number ?: $seller?->phone,
            ],
            'phone'           => $phone,
            'phone_source'    => $phoneSource,
            'item_count'      => (int) $parcel->items->sum('quantity'),
            'reminder'        => $reminder ? [
                'attempt' => $reminder->attempt,
                'status'  => $reminder->status,
                'due_at'  => $reminder->due_at?->toIso8601String(),
                'url'     => $this->notifier->compose($parcel, $reminder->attempt)['url'],
            ] : null,
            'overdue_at'      => $parcel->overdue_at?->toIso8601String(),
            'last_sent'       => $last ? [
                'attempt' => $last->attempt,
                'sent_at' => $last->sent_at?->toIso8601String(),
                'sent_by' => $last->sentBy?->name,
            ] : null,
        ];
    }

    /** The notice to send now: the latest due one, else the next pending one. */
    private function current($history): ?SellerOrderReminder
    {
        return $history->where('status', SellerOrderReminder::DUE)->sortByDesc('attempt')->first()
            ?? $history->where('status', SellerOrderReminder::PENDING)->sortBy('attempt')->first();
    }
}
