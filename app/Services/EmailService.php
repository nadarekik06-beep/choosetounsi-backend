<?php
// ============================================================
// FILE: app/Services/EmailService.php
// ============================================================
namespace App\Services;

use App\Mail\WelcomeUserMail;
use App\Mail\SellerApplicationSubmittedMail;
use App\Mail\SellerApplicationApprovedMail;
use App\Models\User;
use App\Models\SellerApplication;
use App\Models\Product;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailService
{
    /**
     * Send welcome email when a user registers.
     */
    public function sendWelcome(User $user): void
    {
        try {
            // Pull 4 random featured products for the mini catalog
            $featuredProducts = Product::where('is_active', true)
                ->inRandomOrder()
                ->limit(4)
                ->get(['name', 'slug', 'price', 'thumbnail'])
                ->map(fn ($p) => [
                    'name'  => $p->name,
                    'slug'  => $p->slug,
                    'price' => $p->price,
                    'image' => $p->thumbnail
                        ? asset('storage/' . $p->thumbnail)
                        : null,
                ])
                ->toArray();

            Mail::to($user->email)
                ->queue(new WelcomeUserMail($user, $featuredProducts));

        } catch (\Exception $e) {
            Log::error("WelcomeUserMail failed for user #{$user->id}: " . $e->getMessage());
        }
    }

    /**
     * Send confirmation when a seller submits their application.
     */
    public function sendSellerApplicationSubmitted(User $seller, SellerApplication $application): void
    {
        try {
            Mail::to($seller->email)
                ->queue(new SellerApplicationSubmittedMail($seller, $application));
        } catch (\Exception $e) {
            Log::error("SellerApplicationSubmittedMail failed for user #{$seller->id}: " . $e->getMessage());
        }
    }

    /**
     * Send approval email when admin approves a seller application.
     */
    public function sendSellerApplicationApproved(User $seller, SellerApplication $application): void
    {
        try {
            Mail::to($seller->email)
                ->queue(new SellerApplicationApprovedMail($seller, $application));
        } catch (\Exception $e) {
            Log::error("SellerApplicationApprovedMail failed for user #{$seller->id}: " . $e->getMessage());
        }
    }
}