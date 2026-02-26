<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
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
            if ($status === 'banned') {
                $query->where('is_active', false);
            } elseif ($status === 'active') {
                $query->where('is_active', true);
            }
        }

        $users = $query->orderByDesc('created_at')
            ->paginate($request->query('per_page', 15));

        // Add virtual 'status' field
        $users->getCollection()->transform(function ($user) {
            $user->banned_at = $user->is_active ? null : $user->updated_at;
            return $user;
        });

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function show($id)
    {
        $user = User::where('role', 'client')->withCount('orders')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $user]);
    }

    public function ban($id)
    {
        $user = User::where('role', 'client')->findOrFail($id);
        $user->update(['is_active' => false]);
        return response()->json(['success' => true, 'message' => 'User banned successfully.']);
    }

    public function unban($id)
    {
        $user = User::where('role', 'client')->findOrFail($id);
        $user->update(['is_active' => true]);
        return response()->json(['success' => true, 'message' => 'User unbanned successfully.']);
    }

    public function destroy($id)
    {
        $user = User::where('role', 'client')->findOrFail($id);
        $user->delete();
        return response()->json(['success' => true, 'message' => 'User deleted successfully.']);
    }
}