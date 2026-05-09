<?php
// app/Http/Controllers/Api/SellerApplicationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SellerApplicationApprovedMail;
use App\Mail\SellerApplicationSubmittedMail;
use App\Models\User;
use App\Models\SellerApplication;
use App\Notifications\NewSellerApplicationNotification;
use App\Notifications\SellerApplicationReviewedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class SellerApplicationController extends Controller
{
    // ── Shared validation rules ────────────────────────────────────────────

    private function validationRules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'full_name'                  => "{$required}|string|max:255",
            'phone_number'               => "{$required}|string|max:30",
            'business_name'              => "{$required}|string|max:255",
            'business_category'          => "{$required}|string|max:255",
            'business_categories'        => 'nullable|array|max:5',
            'business_categories.*'      => 'string|max:255',
            'business_subcategories'     => 'nullable|array',           // ← NEW
            'business_subcategories.*'   => 'string|max:255',           // ← NEW
            'business_description'       => 'nullable|string|max:2000', // ← NOW OPTIONAL
            'wilaya'                     => "{$required}|string|max:100",
            'city'                       => "{$required}|string|max:100",
            'profile_picture'            => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'sample_images'              => 'nullable|array|max:5',
            'sample_images.*'            => 'image|mimes:jpg,jpeg,png,webp|max:4096',
            'sample_captions'            => 'nullable|array|max:5',
            'sample_captions.*'          => 'nullable|string|max:200',
            'facebook_url'               => 'nullable|url|max:500',
            'instagram_url'              => 'nullable|url|max:500',
            'website_url'                => 'nullable|url|max:500',
            'preferred_plan'             => 'nullable|in:green,red,black',
            'pricing_range'              => 'nullable|in:budget,mid,premium',
        ];
    }

    // ── Helper: build categories array ────────────────────────────────────

    private function resolveCategories(array $validated): array
    {
        $categories = $validated['business_categories']
            ?? [$validated['business_category']];

        return [
            'primary'    => $categories[0] ?? $validated['business_category'] ?? '',
            'all'        => $categories,
            'subcats'    => $validated['business_subcategories'] ?? [],
        ];
    }

    // ── Helper: handle file uploads ───────────────────────────────────────

    private function handleProfilePicture(Request $request, ?string $existing = null): ?string
    {
        if ($request->hasFile('profile_picture')) {
            // Delete old file if replacing
            if ($existing) {
                Storage::disk('public')->delete($existing);
            }
            return $request->file('profile_picture')
                ->store('seller-applications/profiles', 'public');
        }
        return $existing;
    }

    private function handleSampleImages(Request $request, array $existing = []): array
    {
        if (!$request->hasFile('sample_images')) {
            return $existing;
        }

        $paths = $existing; // keep existing images, append new ones
        foreach ($request->file('sample_images') as $image) {
            $paths[] = $image->store('seller-applications/samples', 'public');
        }

        return $paths;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC (seller-facing) ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/seller-applications/mine
     *
     * Returns the authenticated user's latest application with ALL fields
     * needed to pre-fill the 3-step form.
     */
    public function mine(Request $request)
    {
        $application = SellerApplication::where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (!$application) {
            return response()->json(['success' => true, 'data' => null]);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatForFrontend($application),
        ]);
    }

    /**
     * GET /api/seller-applications/status
     * Lightweight — just status + plan info. Used by subscription check.
     */
    public function status(Request $request)
    {
        $application = SellerApplication::where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (!$application) {
            return response()->json(['success' => true, 'data' => null]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id'               => $application->id,
                'status'           => $application->status,
                'plan'             => $application->plan,
                'preferred_plan'   => $application->preferred_plan,
                'rejection_reason' => $application->rejection_reason,
                'reviewed_at'      => $application->reviewed_at?->format('Y-m-d\TH:i:s\Z'),
                'created_at'       => $application->created_at->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);
    }

    /**
     * POST /api/seller-applications
     * Create a new application. Blocked if a pending one already exists.
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
                'data'    => $this->formatForFrontend($existing),
            ], 422);
        }

        $validated = $request->validate($this->validationRules(false));
        $cats      = $this->resolveCategories($validated);

        $profilePicturePath = $this->handleProfilePicture($request);
        $sampleImagePaths   = $this->handleSampleImages($request);

        $application = SellerApplication::create([
            'user_id'                => $user->id,
            'full_name'              => $validated['full_name'],
            'phone_number'           => $validated['phone_number'],
            'business_name'          => $validated['business_name'],
            'business_category'      => $cats['primary'],
            'business_categories'    => $cats['all'],
            'business_subcategories' => $cats['subcats'],           // ← NEW
            'business_description'   => $validated['business_description'] ?? null,
            'wilaya'                 => $validated['wilaya'],
            'city'                   => $validated['city'],
            'profile_picture'        => $profilePicturePath,
            'sample_images'          => $sampleImagePaths ?: null,
            'sample_captions'        => $validated['sample_captions'] ?? null,
            'facebook_url'           => $validated['facebook_url']  ?? null,
            'instagram_url'          => $validated['instagram_url'] ?? null,
            'website_url'            => $validated['website_url']   ?? null,
            'status'                 => 'pending',
            'preferred_plan'         => $validated['preferred_plan'] ?? 'green',
            'plan'                   => 'free',
            'pricing_range'          => $validated['pricing_range'] ?? null,
        ]);

        Notification::send(
            User::getAllAdmins(),
            new NewSellerApplicationNotification(
                $application->id,
                $validated['full_name'],
                $validated['business_name'],
                $user->id
            )
        );

        Mail::to($user->email)->queue(
            new SellerApplicationSubmittedMail($user, $application)
        );

        return response()->json([
            'success' => true,
            'message' => 'Your application has been submitted successfully.',
            'data'    => $this->formatForFrontend($application),
        ], 201);
    }

    /**
     * PUT /api/seller-applications/{id}
     *
     * Seller can edit their own application only if status is pending or rejected.
     * Resets status to 'pending' on update (re-review required).
     */
    public function update(Request $request, int $id)
    {
        $user        = Auth::user();
        $application = SellerApplication::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Only allow editing pending or rejected applications
        if ($application->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Approved applications cannot be edited.',
            ], 422);
        }

        $validated = $request->validate($this->validationRules(true));
        $cats      = $this->resolveCategories($validated);

        $profilePicturePath = $this->handleProfilePicture(
            $request,
            $application->profile_picture
        );

        // For updates: if new sample images sent, append; otherwise keep existing
        $sampleImagePaths = $this->handleSampleImages(
            $request,
            $application->sample_images ?? []
        );

        $application->update([
            'full_name'              => $validated['full_name']         ?? $application->full_name,
            'phone_number'           => $validated['phone_number']      ?? $application->phone_number,
            'business_name'          => $validated['business_name']     ?? $application->business_name,
            'business_category'      => $cats['primary']               ?: $application->business_category,
            'business_categories'    => $cats['all']                   ?: $application->business_categories,
            'business_subcategories' => $cats['subcats'],
            'business_description'   => $validated['business_description'] ?? $application->business_description,
            'wilaya'                 => $validated['wilaya']            ?? $application->wilaya,
            'city'                   => $validated['city']              ?? $application->city,
            'profile_picture'        => $profilePicturePath,
            'sample_images'          => $sampleImagePaths ?: $application->sample_images,
            'sample_captions'        => $validated['sample_captions']  ?? $application->sample_captions,
            'facebook_url'           => $validated['facebook_url']     ?? $application->facebook_url,
            'instagram_url'          => $validated['instagram_url']    ?? $application->instagram_url,
            'website_url'            => $validated['website_url']      ?? $application->website_url,
            'preferred_plan'         => $validated['preferred_plan']   ?? $application->preferred_plan,
            'pricing_range'          => $validated['pricing_range']    ?? $application->pricing_range,
            // Reset to pending so admins re-review the updated application
            'status'                 => 'pending',
            'rejection_reason'       => null,
            'reviewed_at'            => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application updated and resubmitted for review.',
            'data'    => $this->formatForFrontend($application->fresh()),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ADMIN ENDPOINTS (unchanged logic, kept intact)
    // ─────────────────────────────────────────────────────────────────────

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

    public function show(SellerApplication $application)
    {
        $application->load('user');
        return response()->json(['success' => true, 'data' => $application]);
    }

    public function approve(Request $request, SellerApplication $application)
    {
        $application->update([
            'status'      => 'approved',
            'plan'        => 'free',
            'reviewed_at' => now(),
        ]);

        $application->user->update([
            'role'        => 'seller',
            'is_approved' => true,
            'is_active'   => true,
        ]);

        $application->user->notify(
            new SellerApplicationReviewedNotification('approved', $application->business_name)
        );

        Mail::to($application->user->email)->queue(
            new SellerApplicationApprovedMail($application->user, $application)
        );

        return response()->json([
            'success' => true,
            'message' => 'Application approved. User is now a seller.',
        ]);
    }

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

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Format an application for the frontend form pre-fill.
     * Returns everything the 3-step form needs to restore state.
     */
    private function formatForFrontend(SellerApplication $app): array
    {
        $storageBase = config('app.url') . '/storage/';

        return [
            'id'                     => $app->id,
            'status'                 => $app->status,
            'plan'                   => $app->plan,
            'preferred_plan'         => $app->preferred_plan,
            'rejection_reason'       => $app->rejection_reason,
            'reviewed_at'            => $app->reviewed_at?->format('Y-m-d\TH:i:s\Z'),
            'created_at'             => $app->created_at->format('Y-m-d\TH:i:s\Z'),
            // Step 1
            'full_name'              => $app->full_name,
            'phone_number'           => $app->phone_number,
            'business_name'          => $app->business_name,
            'business_category'      => $app->business_category,
            'business_categories'    => $app->business_categories    ?? [],
            'business_subcategories' => $app->business_subcategories ?? [],
            'pricing_range'          => $app->pricing_range,
            // Step 2
            'wilaya'                 => $app->wilaya,
            'city'                   => $app->city,
            // Step 3
            'business_description'   => $app->business_description,
            'profile_picture_url'    => $app->profile_picture
                                            ? $storageBase . $app->profile_picture
                                            : null,
            'sample_images_urls'     => collect($app->sample_images ?? [])
                                            ->map(fn($p) => $storageBase . $p)
                                            ->values()
                                            ->all(),
            'sample_captions'        => $app->sample_captions ?? [],
            'facebook_url'           => $app->facebook_url,
            'instagram_url'          => $app->instagram_url,
            'website_url'            => $app->website_url,
        ];
    }
}