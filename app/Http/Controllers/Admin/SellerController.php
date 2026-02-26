<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class SellerController extends Controller
{
    /**
     * GET /api/admin/sellers
     */
    public function index(Request $request)
    {
        $query = User::sellers()->withCount('products');

        if ($status = $request->query('status')) {
            if ($status === 'approved') {
                $query->where('is_approved', true)->where('is_active', true);
            } elseif ($status === 'pending') {
                $query->where('is_approved', false)->where('is_active', true);
            } elseif ($status === 'suspended') {
                $query->where('is_active', false);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $sellers = $query->orderByDesc('created_at')
            ->paginate($request->query('per_page', 15));

        return response()->json(['success' => true, 'data' => $sellers]);
    }

    /**
     * GET /api/admin/sellers/{id}
     */
    public function show($id)
    {
        $seller = User::where('role', 'seller')
            ->withCount('products')
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $seller]);
    }

    /**
     * PATCH /api/admin/sellers/{id}/approve
     */
    public function approve($id)
    {
        $seller = User::where('role', 'seller')->findOrFail($id);
        $seller->update([
            'is_approved' => true,
            'is_active'   => true,
        ]);

        return response()->json(['success' => true, 'message' => 'Seller approved successfully.']);
    }

    /**
     * PATCH /api/admin/sellers/{id}/reject
     */
    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $seller = User::where('role', 'seller')->findOrFail($id);
        $seller->update([
            'is_approved' => false,
            'is_active'   => false,
        ]);

        return response()->json(['success' => true, 'message' => 'Seller rejected.']);
    }

    /**
     * PATCH /api/admin/sellers/{id}/suspend
     */
    public function suspend($id)
    {
        $seller = User::where('role', 'seller')->findOrFail($id);
        $seller->update(['is_active' => false]);

        return response()->json(['success' => true, 'message' => 'Seller suspended.']);
    }
}