<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Sellers\SuspendedSellerProducts;

/**
 * Seller suspended / rejected (users.is_active → 0): their products leave the storefront.
 * Reactivated (→ 1): the products hidden for that reason come back.
 */
class SellerSuspensionObserver
{
    public function updated(User $user): void
    {
        if ($user->role !== 'seller' || !$user->wasChanged('is_active')) return;

        $service = app(SuspendedSellerProducts::class);
        $user->is_active ? $service->restore((int) $user->id) : $service->hide((int) $user->id);
    }
}
