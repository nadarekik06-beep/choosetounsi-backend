<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/admin/users
     */
    public function index(Request $request)
    {
        $query = User::where('role', 'client')->withCount('orders');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            if ($status === 'banned')  $query->where('is_active', false);
            elseif ($status === 'active') $query->where('is_active', true);
        }

        $users = $query->orderByDesc('created_at')
            ->paginate($request->query('per_page', 15));

        $users->getCollection()->transform(function ($user) {
            $user->banned_at = $user->is_active ? null : $user->updated_at;
            return $user;
        });

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * GET /api/admin/users/{id}
     */
    public function show($id)
    {
        $user = User::where('role', 'client')->withCount('orders')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $user]);
    }

    /**
     * PUT /api/admin/users/{id}
     * Update user information
     */
    public function update(Request $request, $id)
    {
        $user = User::where('role', 'client')->findOrFail($id);

        $validated = $request->validate([
            'name'      => 'sometimes|required|string|max:255',
            'email'     => 'sometimes|required|email|unique:users,email,' . $id,
            'phone'     => 'nullable|string|max:30',
            'address'   => 'nullable|string|max:500',
            'is_active' => 'sometimes|boolean',
        ]);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data'    => $user->fresh()->loadCount('orders'),
        ]);
    }

    /**
     * PATCH /api/admin/users/{id}/ban
     */
    public function ban($id)
    {
        $user = User::where('role', 'client')->findOrFail($id);
        $user->update(['is_active' => false]);
        return response()->json(['success' => true, 'message' => 'User banned successfully.']);
    }

    /**
     * PATCH /api/admin/users/{id}/unban
     */
    public function unban($id)
    {
        $user = User::where('role', 'client')->findOrFail($id);
        $user->update(['is_active' => true]);
        return response()->json(['success' => true, 'message' => 'User unbanned successfully.']);
    }

    /**
     * DELETE /api/admin/users/{id}
     */
    public function destroy($id)
    {
        $user = User::where('role', 'client')->findOrFail($id);
        $user->delete();
        return response()->json(['success' => true, 'message' => 'User deleted successfully.']);
    }
}