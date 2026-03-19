<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SellerApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::sellers()->withCount('products');

        if ($status = $request->query('status')) {
            if ($status === 'approved')       $query->where('is_approved', true)->where('is_active', true);
            elseif ($status === 'pending')    $query->where('is_approved', false)->where('is_active', true);
            elseif ($status === 'suspended')  $query->where('is_active', false);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'data'    => $query->orderByDesc('created_at')->paginate($request->query('per_page', 15)),
        ]);
    }

    public function show($id)
    {
        $seller = User::where('role', 'seller')
            ->withCount('products')
            ->with(['sellerApplication'])
            ->findOrFail($id);

        $app = $seller->sellerApplication;

        return response()->json(['success' => true, 'data' => [
            'id'                   => $seller->id,
            'name'                 => $seller->name,
            'email'                => $seller->email,
            'is_active'            => $seller->is_active,
            'is_approved'          => $seller->is_approved,
            'products_count'       => $seller->products_count,
            'created_at'           => $seller->created_at,
            'full_name'            => $app?->full_name,
            'phone_number'         => $app?->phone_number,
            'business_name'        => $app?->business_name,
            'business_category'    => $app?->business_category,
            'business_description' => $app?->business_description,
            'wilaya'               => $app?->wilaya,
            'city'                 => $app?->city,
            'profile_picture'      => $app?->profile_picture ? asset('storage/' . $app->profile_picture) : null,
            'facebook_url'         => $app?->facebook_url,
            'instagram_url'        => $app?->instagram_url,
            'website_url'          => $app?->website_url,
            'app_status'           => $app?->status,
        ]]);
    }

    public function update(Request $request, $id)
    {
        $seller    = User::where('role', 'seller')->findOrFail($id);
        $validated = $request->validate([
            'name'       => 'sometimes|required|string|max:255',
            'email'      => 'sometimes|required|email|unique:users,email,' . $id,
            'phone'      => 'nullable|string|max:30',
            'store_name' => 'nullable|string|max:255',
            'address'    => 'nullable|string|max:500',
            'is_active'  => 'sometimes|boolean',
        ]);
        $seller->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Seller updated.',
            'data'    => $seller->fresh()->loadCount('products'),
        ]);
    }

    /**
     * DELETE /api/admin/sellers/{id}
     *
     * Deletes the seller user and their related data.
     * Uses a transaction so nothing is half-deleted on error.
     *
     * NOTE: The {id} here is the USER id (users.id), not a seller_application id.
     */
    public function destroy($id)
    {
        // Find the user — allow any role so we can delete even if role was changed
        $seller = User::findOrFail($id);

        DB::transaction(function () use ($seller) {

            // 1. Delete all products (Product::boot handles image file cleanup)
            if ($seller->products()->exists()) {
                $seller->products()->each(function ($product) {
                    $product->delete();
                });
            }

            // 2. Delete seller applications linked to this user
            SellerApplication::where('user_id', $seller->id)->delete();

            // 3. Delete the user itself
            $seller->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Seller deleted successfully.',
        ]);
    }

    /**
     * PATCH /api/admin/sellers/{id}/role
     * Body: { "role": "client" | "seller" }
     */
    public function changeRole(Request $request, $id)
    {
        $request->validate(['role' => 'required|in:client,seller']);

        $seller = User::findOrFail($id);
        $seller->update([
            'role'        => $request->role,
            'is_approved' => $request->role === 'seller' ? $seller->is_approved : false,
            'is_active'   => $request->role === 'seller' ? $seller->is_active   : true,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Role changed to {$request->role}.",
        ]);
    }

    public function approve($id)
    {
        User::where('role', 'seller')->findOrFail($id)
            ->update(['is_approved' => true, 'is_active' => true]);

        return response()->json(['success' => true, 'message' => 'Seller approved.']);
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        User::where('role', 'seller')->findOrFail($id)
            ->update(['is_approved' => false, 'is_active' => false]);

        return response()->json(['success' => true, 'message' => 'Seller rejected.']);
    }

    public function suspend($id)
    {
        User::where('role', 'seller')->findOrFail($id)
            ->update(['is_active' => false]);

        return response()->json(['success' => true, 'message' => 'Seller suspended.']);
    }
}