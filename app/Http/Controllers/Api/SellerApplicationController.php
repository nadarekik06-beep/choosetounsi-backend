<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SellerApplicationController extends Controller
{
    /**
     * Submit seller application
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Check if user already has a pending/approved application
        $existing = SellerApplication::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already have an application in progress.',
                'application' => $existing,
            ], 400);
        }

        // Validate
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20',
            'business_name' => 'required|string|max:255',
            'business_category' => 'required|string|max:255',
            'business_description' => 'required|string|min:50',
            'wilaya' => 'required|string',
            'city' => 'required|string',
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'sample_images' => 'required|array|min:2|max:5',
            'sample_images.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            'facebook_url' => 'nullable|url',
            'instagram_url' => 'nullable|url',
            'website_url' => 'nullable|url',
        ]);

        // Upload profile picture
        $profilePath = null;
        if ($request->hasFile('profile_picture')) {
            $profilePath = $request->file('profile_picture')
                ->store('seller-applications/profiles', 'public');
        }

        // Upload sample images
        $samplePaths = [];
        if ($request->hasFile('sample_images')) {
            foreach ($request->file('sample_images') as $image) {
                $samplePaths[] = $image->store('seller-applications/samples', 'public');
            }
        }

        // Create application
        $application = SellerApplication::create([
            'user_id' => $user->id,
            'full_name' => $validated['full_name'],
            'phone_number' => $validated['phone_number'],
            'business_name' => $validated['business_name'],
            'business_category' => $validated['business_category'],
            'business_description' => $validated['business_description'],
            'wilaya' => $validated['wilaya'],
            'city' => $validated['city'],
            'profile_picture' => $profilePath,
            'sample_images' => $samplePaths,
            'facebook_url' => $validated['facebook_url'] ?? null,
            'instagram_url' => $validated['instagram_url'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Application submitted successfully! We will review it soon.',
            'application' => $application,
        ], 201);
    }

    /**
     * Get user's application status
     */
    public function status(Request $request)
    {
        $application = SellerApplication::where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (!$application) {
            return response()->json([
                'has_application' => false,
            ]);
        }

        return response()->json([
            'has_application' => true,
            'application' => $application,
        ]);
    }

    /**
     * Admin: List all applications
     */
    public function index(Request $request)
    {
        $query = SellerApplication::with(['user', 'reviewer']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $applications = $query->latest()->paginate(20);

        return response()->json($applications);
    }

    /**
     * Admin: Approve application
     */
    public function approve(Request $request, SellerApplication $application)
    {
        $application->approve($request->user()->id);

        return response()->json([
            'message' => 'Seller application approved successfully.',
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Admin: Reject application
     */
    public function reject(Request $request, SellerApplication $application)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $application->reject($request->user()->id, $validated['reason']);

        return response()->json([
            'message' => 'Application rejected.',
            'application' => $application->fresh(),
        ]);
    }
}