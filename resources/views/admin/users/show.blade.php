@extends('admin.layout')

@section('title', 'User Details')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-user"></i> User Details</h1>
    <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Users
    </a>
</div>

<div class="row">
    <!-- User Information -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">User Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="text-muted">Name</label>
                    <p class="fw-bold">{{ $user->name }}</p>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted">Email</label>
                    <p>{{ $user->email }}</p>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted">Role</label>
                    <p>
                        @if($user->role == 'admin')
                            <span class="badge bg-danger">Admin</span>
                        @elseif($user->role == 'seller')
                            <span class="badge bg-primary">Seller</span>
                        @else
                            <span class="badge bg-secondary">Client</span>
                        @endif
                    </p>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted">Status</label>
                    <p>
                        @if($user->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-danger">Inactive</span>
                        @endif
                    </p>
                </div>
                
                @if($user->role == 'seller')
                    <div class="mb-3">
                        <label class="text-muted">Approval Status</label>
                        <p>
                            @if($user->is_approved)
                                <span class="badge bg-success">Approved</span>
                            @else
                                <span class="badge bg-warning">Pending Approval</span>
                            @endif
                        </p>
                    </div>
                @endif
                
                <div class="mb-3">
                    <label class="text-muted">Joined</label>
                    <p>{{ $user->created_at->format('M d, Y H:i') }}</p>
                </div>

                <!-- Change Role -->
                <div class="mb-3">
                    <form method="POST" action="{{ route('admin.users.update-role', $user) }}">
                        @csrf
                        @method('PATCH')
                        <label class="form-label">Change Role</label>
                        <select name="role" class="form-select mb-2" {{ $user->id == auth()->id() ? 'disabled' : '' }}>
                            <option value="seller" {{ $user->role == 'seller' ? 'selected' : '' }}>Seller</option>
                            <option value="client" {{ $user->role == 'client' ? 'selected' : '' }}>Client</option>
                        </select>
                        @if($user->id != auth()->id())
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save"></i> Update Role
                            </button>
                        @endif
                    </form>
                </div>

                <!-- Actions -->
                <div class="d-grid gap-2">
                    <form method="POST" action="{{ route('admin.users.toggle-status', $user) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn {{ $user->is_active ? 'btn-warning' : 'btn-success' }} w-100" {{ $user->id == auth()->id() ? 'disabled' : '' }}>
                            <i class="fas fa-power-off"></i> {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </form>

                    @if($user->id != auth()->id())
                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Are you sure you want to delete this user?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="fas fa-trash"></i> Delete User
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- User Activity -->
    <div class="col-md-8">
        @if($user->role == 'seller')
            <!-- Products -->
            <div class="card mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-box"></i> Products ({{ $user->products->count() }})</h5>
                </div>
                <div class="card-body">
                    @if($user->products->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Price</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($user->products as $product)
                                        <tr>
                                            <td>{{ $product->name }}</td>
                                            <td>{{ number_format($product->price, 2) }} TND</td>
                                            <td>{{ $product->stock }}</td>
                                            <td>
                                                @if($product->is_approved)
                                                    <span class="badge bg-success">Approved</span>
                                                @else
                                                    <span class="badge bg-warning">Pending</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-center text-muted mb-0">No products yet</p>
                    @endif
                </div>
            </div>
        @endif

        <!-- Orders -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-shopping-cart"></i> Orders ({{ $user->orders->count() }})</h5>
            </div>
            <div class="card-body">
                @if($user->orders->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($user->orders as $order)
                                    <tr>
                                        <td>{{ $order->order_number }}</td>
                                        <td>{{ number_format($order->total_amount, 2) }} TND</td>
                                        <td>
                                            @if($order->status == 'completed')
                                                <span class="badge bg-success">Completed</span>
                                            @elseif($order->status == 'pending')
                                                <span class="badge bg-warning">Pending</span>
                                            @else
                                                <span class="badge bg-info">{{ ucfirst($order->status) }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $order->created_at->format('M d, Y') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-center text-muted mb-0">No orders yet</p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection