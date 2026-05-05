<?php
// app/Http/Controllers/Admin/SellerController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Notifications\ProductReviewedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerController extends Controller
{
    public function index(Request $request)
    {
    $query = User::sellers()->withCount('products')->with('sellerApplication');

        if ($status = $request->query('status')) {
            if ($status === 'approved')       $query->where('is_approved', true)->where('is_active', true);
            elseif ($status === 'pending')    $query->where('is_approved', false)->where('is_active', true);
            elseif ($status === 'suspended')  $query->where('is_active', false);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

$paginated = $query->orderByDesc('created_at')->paginate($request->query('per_page', 15));

        $paginated->getCollection()->transform(function (User $seller) {
            $app = $seller->sellerApplication;
            $seller->active_plan    = $app?->plan           ?? 'free';
            $seller->preferred_plan = $app?->preferred_plan ?? 'green';
            return $seller;
        });

        return response()->json([
            'success' => true,
            'data'    => $paginated,
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
            'full_name'            => $app ? $app->full_name : null,
            'phone_number'         => $app ? $app->phone_number : null,
            'business_name'        => $app ? $app->business_name : null,
            
            'business_category'    => $app ? $app->business_category : null,
            'business_categories'  => $app?->business_categories ?? [],  
            'business_description' => $app ? $app->business_description : null,
            'wilaya'               => $app ? $app->wilaya : null,
            'city'                 => $app ? $app->city : null,
            'profile_picture'      => ($app && $app->profile_picture) ? asset('storage/' . $app->profile_picture) : null,
            'sample_captions'      => $app?->sample_captions ?? [],
            'facebook_url'         => $app ? $app->facebook_url : null,
            'instagram_url'        => $app ? $app->instagram_url : null,
            'website_url'          => $app ? $app->website_url : null,
            'app_status'           => $app ? $app->status       : null,
            'active_plan'          => $app ? $app->plan         : 'free',
            'preferred_plan'       => $app ? $app->preferred_plan : 'green',
            'pricing_range'        => $app?->pricing_range,
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

    public function destroy($id)
    {
        $seller = User::findOrFail($id);

        DB::transaction(function () use ($seller) {
            if ($seller->products()->exists()) {
                $seller->products()->each(function ($product) {
                    $product->delete();
                });
            }
            SellerApplication::where('user_id', $seller->id)->delete();
            $seller->delete();
        });

        return response()->json(['success' => true, 'message' => 'Seller deleted successfully.']);
    }

    public function changeRole(Request $request, $id)
    {
        $request->validate(['role' => 'required|in:client,seller']);
        $seller = User::findOrFail($id);
        $seller->update([
            'role'        => $request->role,
            'is_approved' => $request->role === 'seller' ? $seller->is_approved : false,
            'is_active'   => $request->role === 'seller' ? $seller->is_active   : true,
        ]);

        return response()->json(['success' => true, 'message' => 'Role changed to ' . $request->role . '.']);
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

    /**
     * PATCH /api/admin/products/{id}/approve
     */
    public function approveProduct(Request $request, $id)
    {
        $product = Product::with('seller')->findOrFail($id);
        $product->update(['is_approved' => true]);

        if ($product->seller) {
            $product->seller->notify(
                new ProductReviewedNotification(
                    'approved',
                    $product->id,
                    $product->name
                )
            );
        }

        return response()->json(['success' => true, 'message' => 'Product approved.']);
    }

    /**
     * PATCH /api/admin/products/{id}/reject
     */
    public function rejectProduct(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $product = Product::with('seller')->findOrFail($id);
        $product->update(['is_approved' => false]);

        if ($product->seller) {
            $product->seller->notify(
                new ProductReviewedNotification(
                    'rejected',
                    $product->id,
                    $product->name,
                    $request->reason
                )
            );
        }

        return response()->json(['success' => true, 'message' => 'Product rejected.']);
    }
}