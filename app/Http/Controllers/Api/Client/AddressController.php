<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AddressController
 *
 * Manages the authenticated user's address book.
 *
 * Routes (all under auth:sanctum):
 *   GET    /api/addresses              → index
 *   POST   /api/addresses              → store
 *   PUT    /api/addresses/{id}         → update
 *   DELETE /api/addresses/{id}         → destroy
 *   PATCH  /api/addresses/{id}/default → setDefault
 *
 * Security: every query is scoped to auth()->id() — a user can never
 * read or modify another user's addresses.
 *
 * Default address logic:
 *   Setting a new default is done in a single DB transaction:
 *     1. Set all user's addresses to is_default = false
 *     2. Set the target address to is_default = true
 *   This guarantees exactly one default at all times.
 *
 * Deletion edge case:
 *   If the deleted address was the default, we auto-promote the most
 *   recently created remaining address to default (if any exists).
 *
 * Limit: max 10 addresses per user to prevent abuse.
 */
class AddressController extends Controller
{
    private const MAX_ADDRESSES = 10;

    /* ── GET /api/addresses ── */
    public function index(Request $request)
    {
        $addresses = UserAddress::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $addresses,
        ]);
    }

    /* ── POST /api/addresses ── */
    public function store(Request $request)
    {
        $user = $request->user();

        // Enforce address limit
        $count = UserAddress::where('user_id', $user->id)->count();
        if ($count >= self::MAX_ADDRESSES) {
            return response()->json([
                'success' => false,
                'message' => 'You can save a maximum of ' . self::MAX_ADDRESSES . ' addresses.',
            ], 422);
        }

        $data = $request->validate([
            'label'   => 'nullable|string|max:100',
            'wilaya'  => 'required|string|max:100',
            'address' => 'required|string|max:500',
            'phone'   => 'required|string|max:30',
            'notes'   => 'nullable|string|max:1000',
            'is_default' => 'nullable|boolean',
        ]);

        $data['user_id']    = $user->id;
        $data['label']      = $data['label'] ?? 'Home';
        $data['is_default'] = $data['is_default'] ?? false;

        DB::beginTransaction();
        try {
            // If this is the user's first address OR they explicitly want it as default,
            // clear any existing default first.
            $isFirstAddress = $count === 0;
            if ($isFirstAddress || !empty($data['is_default'])) {
                UserAddress::where('user_id', $user->id)
                    ->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            $address = UserAddress::create($data);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Address saved.',
                'data'    => $address,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to save address.',
            ], 500);
        }
    }

    /* ── PUT /api/addresses/{id} ── */
    public function update(Request $request, $id)
    {
        $user    = $request->user();
        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);

        $data = $request->validate([
            'label'   => 'nullable|string|max:100',
            'wilaya'  => 'required|string|max:100',
            'address' => 'required|string|max:500',
            'phone'   => 'required|string|max:30',
            'notes'   => 'nullable|string|max:1000',
        ]);

        $data['label'] = $data['label'] ?? 'Home';
        $address->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Address updated.',
            'data'    => $address->fresh(),
        ]);
    }

    /* ── DELETE /api/addresses/{id} ── */
    public function destroy(Request $request, $id)
    {
        $user    = $request->user();
        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);

        $wasDefault = $address->is_default;
        $address->delete();

        // Auto-promote the most recent remaining address to default
        // if the deleted one was the default.
        if ($wasDefault) {
            $next = UserAddress::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->first();

            if ($next) {
                $next->update(['is_default' => true]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Address deleted.',
        ]);
    }

    /* ── PATCH /api/addresses/{id}/default ── */
    public function setDefault(Request $request, $id)
    {
        $user    = $request->user();
        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);

        DB::beginTransaction();
        try {
            // Clear all defaults for this user, then set the target
            UserAddress::where('user_id', $user->id)
                ->update(['is_default' => false]);

            $address->update(['is_default' => true]);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Default address updated.',
                'data'    => $address->fresh(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update default address.',
            ], 500);
        }
    }
}