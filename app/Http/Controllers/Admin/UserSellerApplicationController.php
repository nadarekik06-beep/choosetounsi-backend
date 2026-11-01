<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sellers\SellerApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin > Users "Create seller application": the admin fills the storefront
 * application form on a user's behalf, and can approve it on the spot.
 */
class UserSellerApplicationController extends Controller
{
    public function __construct(private SellerApplicationService $applications) {}

    /** POST /api/admin/users/{id}/seller-application */
    public function store(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        if ($user->role === 'seller') {
            return response()->json(['success' => false, 'message' => 'This user is already a seller.'], 422);
        }
        if ($user->role !== 'client') {
            return response()->json(['success' => false, 'message' => 'Only customer accounts can become sellers.'], 422);
        }
        if ($this->applications->pendingFor($user)) {
            return response()->json([
                'success' => false,
                'message' => 'This user already has a pending seller application. Review it in Applications.',
            ], 422);
        }

        $request->validate(['approve_immediately' => 'nullable|boolean']);
        $admin       = $request->user();
        $application = $this->applications->create($user, $request, $admin);
        $approved    = false;

        if ($request->boolean('approve_immediately')) {
            // The form requires the zone and pin, so this only fails on a stale zone: the application stays pending
            if ($missing = $this->applications->approvalBlockers($application)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Application created, but not approved: the pickup address is incomplete (' . implode(', ', $missing) . '). It is pending in Applications.',
                    'data'    => ['application_id' => $application->id, 'status' => 'pending'],
                ], 422);
            }
            $this->applications->approve($application->fresh());
            $approved = true;
        }

        Log::info("[SellerApplication] #{$application->id} created for user {$user->id} by admin {$admin->id}" . ($approved ? ' and approved' : ''));

        return response()->json([
            'success' => true,
            'message' => $approved
                ? 'Seller application created and approved. The user is now a seller.'
                : 'Seller application created. It is pending in Applications.',
            'data'    => ['application_id' => $application->id, 'status' => $approved ? 'approved' : 'pending'],
        ], 201);
    }
}
