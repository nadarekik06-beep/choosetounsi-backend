@extends('admin.layout')

@section('title', 'Seller Details')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-store"></i> Seller Details</h1>
    <a href="{{ route('admin.sellers.index') }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Sellers
    </a>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header bg-white">
                <h5 class="mb-0">Seller Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="text-muted">Name</label>
                    <h5>{{ $seller->name }}</h5>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted">Email</label>
                    <p>{{ $seller->email }}</p>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted">Approval Status</label>
                    <p>
                        @if($seller->is_approved)
                            <span class="badge bg-success">
                                <i class="fas fa-check"></i> Approved
                            </span>
                        @else
                            <span class="badge bg-warning">
                                <i class="fas fa-clock"></i> Pending Approval
                            </span>
                        @endif
                    </p>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted">Account Status</label>
                    <p>
                        @if($seller->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-danger">Inactive</span>
                        @endif
                    </p>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted">Joined</label>
                    <p>{{ $seller->created_at->format('M d, Y H:i') }}</p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-cog"></i> Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    @if(!$seller->is_approved)
                        <form method="POST" action="{{ route('admin.sellers.approve', $seller) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-check"></i> Approve Seller
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.sellers.reject', $seller) }}" onsubmit="return confirm('This will deactivate all their products. Continue?');">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="fas fa-times"></i> Revoke Approval
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-primary">{{ $seller->products->count() }}</h3>
                        <p class="text-muted mb-0">Total Products</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-success">{{ $seller->products->where('is_approved', true)->count() }}</h3>
                        <p class="text-muted mb-0">Approved Products</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Products -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-box"></i> Products</h5>
            </div>
            <div class="card-body">
                @if($seller->products->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($seller->products as $product)
                                    <tr>
                                        <td>
                                            <strong>{{ $product->name }}</strong>
                                            @if($product->sku)
                                                <br><small class="text-muted">{{ $product->sku }}</small>
                                            @endif
                                        </td>
                                        <td>{{ number_format($product->price, 2) }} TND</td>
                                        <td>{{ $product->stock }}</td>
                                        <td>
                                            @if($product->is_approved)
                                                <span class="badge bg-success">Approved</span>
                                            @else
                                                <span class="badge bg-warning">Pending</span>
                                            @endif
                                            
                                            @if(!$product->is_active)
                                                <span class="badge bg-secondary">Inactive</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('admin.products.show', $product) }}" class="btn btn-sm btn-info">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-center text-muted mb-0">
                        <i class="fas fa-inbox"></i> No products yet
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection