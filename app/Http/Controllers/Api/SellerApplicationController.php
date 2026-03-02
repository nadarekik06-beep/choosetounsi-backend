<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class SellerApplicationController extends Controller
{
    /**
     * POST /api/seller-applications
     * Submit a new seller application.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Prevent duplicate pending applications
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
        ]);

        // Handle profile picture
        $profilePicturePath = null;
        if ($request->hasFile('profile_picture')) {
            $profilePicturePath = $request->file('profile_picture')
                ->store('seller-applications/profiles', 'public');
        }

        // Handle sample images
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
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your application has been submitted successfully.',
            'data'    => $application,
        ], 201);
    }

    /**
     * GET /api/seller-applications/status
     * Get the current user's application status.
     */
    public function status()
    {
        $user = Auth::user();

        $application = SellerApplication::where('user_id', $user->id)
            ->latest()
            ->first();

        if (!$application) {
            return response()->json([
                'success' => true,
                'data'    => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $application,
        ]);
    }

    // ── Admin methods ─────────────────────────────────────────────────

    /**
     * GET /api/admin/seller-applications
     */
    public function index(Request $request)
    {
        $query = SellerApplication::with('user')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('business_name', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) => $u->where('email', 'like', "%{$search}%"));
            });
        }

        $applications = $query->paginate($request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $applications,
        ]);
    }

    /**
     * GET /api/admin/seller-applications/{application}
     */
    public function show(SellerApplication $application)
    {
        $application->load('user');

        return response()->json([
            'success' => true,
            'data'    => $application,
        ]);
    }

    /**
     * POST /api/admin/seller-applications/{application}/approve
     */
    public function approve(Request $request, SellerApplication $application)
    {
        $admin = Auth::user();

        $application->update([
            'status'      => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ]);

        // Promote user to seller
        $application->user->update([
            'role'        => 'seller',
            'is_approved' => true,
            'is_active'   => true,
        ]);

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
        $request->validate([
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        $admin = Auth::user();

        $application->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'reviewed_at'      => now(),
            'reviewed_by'      => $admin->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application rejected.',
        ]);
    }
}