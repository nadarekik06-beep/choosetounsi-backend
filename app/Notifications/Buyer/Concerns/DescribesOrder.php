<?php

namespace App\Notifications\Buyer\Concerns;

use App\Models\Order;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Helpers\PlatformUser;
use Illuminate\Support\Collection;

/** Order wording shared by the buyer order / payment notifications. */
trait DescribesOrder
{
    protected function ref(Order $order): string
    {
        return (string) ($order->order_number ?: '#' . $order->id);
    }

    /** /orders?order=ID opens and scrolls to the order (storefront). */
    protected function orderLink(Order $order): string
    {
        return '/orders?order=' . $order->id;
    }

    /** Shop names of these sub-orders ("ChooseTounsi" for the platform's own products). */
    protected function shopNames(Collection $sellerOrders): array
    {
        $sellerIds = $sellerOrders->pluck('seller_id')->filter()->unique()->values();
        if ($sellerIds->isEmpty()) return [];

        $platformId = PlatformUser::id();

        $businessNames = SellerApplication::whereIn('user_id', $sellerIds)
            ->approved()->orderByDesc('id')->get(['user_id', 'business_name'])
            ->unique('user_id')->pluck('business_name', 'user_id');

        return $sellerIds->map(function ($id) use ($sellerOrders, $businessNames, $platformId) {
            if ($platformId && (int) $id === (int) $platformId) return config('app.name');
            return $businessNames[$id]
                ?? $sellerOrders->firstWhere('seller_id', $id)?->seller?->name
                ?? config('app.name');
        })->values()->all();
    }

    /** @return Collection<int, SellerOrder> */
    protected function sellerOrdersOf(Order $order, array $ids = []): Collection
    {
        return $order->sellerOrders()
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->with('seller:id,name')
            ->get();
    }

    protected function itemCount(Order $order): int
    {
        return (int) $order->items()->sum('quantity');
    }
}
