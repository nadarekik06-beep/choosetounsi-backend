<?php
// app/Http/Controllers/Api/SellerApplicationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SellerApplication;
use App\Notifications\NewSellerApplicationNotification;
use App\Notifications\SellerApplicationReviewedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class SellerApplicationController extends Controller
{
    // ── Public / authenticated ─────────────────────────────────────────────

    /**
     * POST /api/seller-applications
     *
     * What changed vs old version:
     *   - `subscription_plan` input field renamed to `preferred_plan`
     *   - We store preferred_plan (user's interest) but ALWAYS set plan = 'free'
     *     because the active plan is only activated after approval + payment.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $existing = SellerApplication::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending application.',
            ], 422);
        }

        $validated = $request->validate([
            'full_name'            => 'required|string|max:255',
            'phone_number'         => 'required|string|max:30',
            'business_name'        => 'required|string|max:255',
            'business_category'    => 'required|string|max:255',
            'business_description' => 'required|string|max:2000',
            'wilaya'               => 'required|string|max:100',
            'city'                 => 'required|string|max:100',
            'profile_picture'      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'sample_images'        => 'nullable|array|max:5',
            'sample_images.*'      => 'image|mimes:jpg,jpeg,png,webp|max:4096',
            'facebook_url'         => 'nullable|url|max:500',
            'instagram_url'        => 'nullable|url|max:500',
            'website_url'          => 'nullable|url|max:500',
            // preferred_plan: what the user expressed interest in
            // Validated as green/red/black to match pricing page options
            'preferred_plan'       => 'nullable|in:green,red,black',
        ]);

        $profilePicturePath = null;
        if ($request->hasFile('profile_picture')) {
            $profilePicturePath = $request->file('profile_picture')
                ->store('seller-applications/profiles', 'public');
        }

        $sampleImagePaths = [];
        if ($request->hasFile('sample_images')) {
            foreach ($request->file('sample_images') as $image) {
                $sampleImagePaths[] = $image->store('seller-applications/samples', 'public');
            }
        }

        $application = SellerApplication::create([
            'user_id'              => $user->id,
            'full_name'            => $validated['full_name'],
            'phone_number'         => $validated['phone_number'],
            'business_name'        => $validated['business_name'],
            'business_category'    => $validated['business_category'],
            'business_description' => $validated['business_description'],
            'wilaya'               => $validated['wilaya'],
            'city'                 => $validated['city'],
            'profile_picture'      => $profilePicturePath,
            'sample_images'        => $sampleImagePaths ?: null,
            'facebook_url'         => $validated['facebook_url'] ?? null,
            'instagram_url'        => $validated['instagram_url'] ?? null,
            'website_url'          => $validated['website_url'] ?? null,
            'status'               => 'pending',
            // preferred_plan: what the user expressed interest in (default: green = free)
            'preferred_plan'       => $validated['preferred_plan'] ?? 'green',
            // plan: ALWAYS 'free' at application time — upgraded after approval + payment
            'plan'                 => 'free',
        ]);

        // Notify all admins
        Notification::send(
            User::getAllAdmins(),
            new NewSellerApplicationNotification(
                $application->id,
                $validated['full_name'],
                $validated['business_name'],
                $user->id
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Your application has been submitted successfully.',
            'data'    => $application,
        ], 201);
    }

    /**
     * GET /api/seller-applications/status
     */
    // app/Http/Controllers/Api/SellerApplicationController.php
// Only the status() method — don't touch anything else.

public function status(Request $request): \Illuminate\Http\JsonResponse
{
    $application = \App\Models\SellerApplication::where('user_id', $request->user()->id)
        ->latest()
        ->first();

    if (! $application) {
        return response()->json(['success' => true, 'data' => null]);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'id'              => $application->id,
            'status'          => $application->status,
            'plan'            => $application->plan,           // ← ensure this is here
            'preferred_plan'  => $application->preferred_plan,
            'rejection_reason'=> $application->rejection_reason,
            'reviewed_at'     => $application->reviewed_at,
            'created_at'      => $application->created_at->format('Y-m-d\TH:i:s\Z'),
        ],
    ]);
}

    // ── Admin methods ──────────────────────────────────────────────────────

    /**
     * GET /api/admin/seller-applications
     */
    public function index(Request $request)
    {
        $query = SellerApplication::with('user')->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', '%' . $search . '%')
                  ->orWhere('business_name', 'like', '%' . $search . '%')
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('email', 'like', '%' . $search . '%');
                  });
            });
        }

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->query('per_page', 15)),
        ]);
    }

    /**
     * GET /api/admin/seller-applications/{application}
     */
    public function show(SellerApplication $application)
    {
        $application->load('user');
        return response()->json(['success' => true, 'data' => $application]);
    }

    /**
     * POST /api/admin/seller-applications/{application}/approve
     *
     * What changed vs old version:
     *   - Explicitly sets plan = 'free' on approval (not the preferred_plan)
     *   - preferred_plan is preserved so the seller dashboard can later
     *     show "You selected Red Pepper — upgrade now"
     */
    public function approve(Request $request, SellerApplication $application)
    {
        $application->update([
            'status'      => 'approved',
            'plan'        => 'free',   // Active plan is ALWAYS free at approval
            // preferred_plan intentionally NOT changed — it stays as the seller's
            // expressed interest so the dashboard can show upgrade prompts.
            'reviewed_at' => now(),
        ]);

        $application->user->update([
            'role'        => 'seller',
            'is_approved' => true,
            'is_active'   => true,
        ]);

        $application->user->notify(
            new SellerApplicationReviewedNotification(
                'approved',
                $application->business_name
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Application approved. User is now a seller.',
        ]);
    }

    /**
     * POST /api/admin/seller-applications/{application}/reject
     */
    public function reject(Request $request, SellerApplication $application)
    {
        $request->validate(['rejection_reason' => 'nullable|string|max:1000']);

        $application->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'reviewed_at'      => now(),
        ]);

        $application->user->notify(
            new SellerApplicationReviewedNotification(
                'rejected',
                $application->business_name,
                $request->rejection_reason
            )
        );

        return response()->json(['success' => true, 'message' => 'Application rejected.']);
    }
}