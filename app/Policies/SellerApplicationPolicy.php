<?php

namespace App\Policies;

use App\Models\SellerApplication;
use App\Models\User;

class SellerApplicationPolicy
{
    /**
     * Store profile (logo, description, activity categories) from "Paramètres
     * de la boutique": only the owner, an active approved seller, on their
     * approved application.
     */
    public function updateStoreProfile(User $user, SellerApplication $application): bool
    {
        return $application->user_id === $user->id
            && $application->status === 'approved'
            && $user->role === 'seller'
            && (bool) $user->is_active
            && (bool) $user->is_approved;
    }
}
