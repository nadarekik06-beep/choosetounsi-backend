<?php

namespace App\Services\Chat;

use App\Models\Order;
use App\Models\User;

/**
 * Recent orders for the chatbot's "track my order" answer.
 *
 * Privacy: every query is scoped to the authenticated user's id (the Sanctum
 * token owner). An order number typed in the message only narrows the
 * owner's own orders — it can never reach another customer's order.
 */
class OrderLookup
{
    private const LIMIT = 5;

    private const STATUS_LABELS = [
        'pending'          => ['en' => 'Pending',          'fr' => 'En attente',   'ar' => 'في الانتظار'],
        'processing'       => ['en' => 'Processing',       'fr' => 'En cours',     'ar' => 'قيد المعالجة'],
        'confirmed'        => ['en' => 'Confirmed',        'fr' => 'Confirmée',    'ar' => 'مؤكدة'],
        'out_for_delivery' => ['en' => 'Out for Delivery', 'fr' => 'En livraison', 'ar' => 'في الطريق'],
        'delivered'        => ['en' => 'Delivered',        'fr' => 'Livrée',       'ar' => 'وصلت'],
        'completed'        => ['en' => 'Completed',        'fr' => 'Terminée',     'ar' => 'مكتملة'],
        'cancelled'        => ['en' => 'Cancelled',        'fr' => 'Annulée',      'ar' => 'ملغاة'],
        'refunded'         => ['en' => 'Refunded',         'fr' => 'Remboursée',   'ar' => 'مسترجعة'],
    ];

    private const PAYMENT_LABELS = [
        'paid'     => ['en' => 'paid',     'fr' => 'payée',       'ar' => 'مدفوعة'],
        'unpaid'   => ['en' => 'unpaid',   'fr' => 'non payée',   'ar' => 'غير مدفوعة'],
        'refunded' => ['en' => 'refunded', 'fr' => 'remboursée',  'ar' => 'مسترجعة'],
    ];

    /**
     * @return array<array{order_number: string, status: string, title: string, description: string}>
     */
    public function recentFor(User $user, string $message, string $lang): array
    {
        $query = Order::query()
            ->where('user_id', $user->id)          // ← the only ownership rule; never removed
            ->withCount('items')
            ->latest()
            ->limit(self::LIMIT);

        // "where is ORD-7KQ2M9XA?" → narrow to that number, still within this user's orders.
        if (preg_match('/\bORD-?([A-Z0-9]{8})\b/i', $message, $m)) {
            $own = (clone $query)->where('order_number', 'ORD-' . strtoupper($m[1]))->get();
            if ($own->isNotEmpty()) {
                return $this->format($own, $lang);
            }
        }

        return $this->format($query->get(), $lang);
    }

    public function statusLabel(string $status, string $lang): string
    {
        return self::STATUS_LABELS[$status][$lang] ?? $status;
    }

    private function format($orders, string $lang): array
    {
        return $orders->map(function (Order $order) use ($lang) {
            $date    = optional($order->created_at)->format('d/m/Y');
            $total   = number_format((float) $order->total_amount, 3, '.', '') . ($lang === 'ar' ? ' د.ت' : ' DT');
            $items   = (int) $order->items_count;
            $payment = self::PAYMENT_LABELS[$order->payment_status][$lang] ?? $order->payment_status;

            $itemsText = [
                'en' => $items . ' item' . ($items === 1 ? '' : 's'),
                'fr' => $items . ' article' . ($items === 1 ? '' : 's'),
                'ar' => $items . ' منتج',
            ][$lang];

            return [
                'order_number' => $order->order_number,
                'status'       => $order->status,
                'title'        => '#' . $order->order_number . ' — ' . $this->statusLabel($order->status, $lang),
                'description'  => implode(' · ', array_filter([$date, $itemsText, $total, $payment])),
            ];
        })->values()->all();
    }
}
