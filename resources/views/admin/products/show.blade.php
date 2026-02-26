@extends('admin.layout')

@section('title', 'Product Details')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-box"></i> Product Details</h1>
    <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Products
    </a>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <h3>{{ $product->name }}</h3>
                
                <div class="mb-4">
                    @if($product->is_approved)
                        <span class="badge bg-success"><i class="fas fa-check"></i> Approved</span>
                    @else
                        <span class="badge bg-warning"><i class="fas fa-clock"></i> Pending</span>
                    @endif
                    
                    @if($product->is_active)
                        <span class="badge bg-success">Active</span>
                    @else
                        <span class="badge bg-secondary">Inactive</span>
                    @endif
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="text-muted">Price</label>
                        <h4 class="text-primary">{{ number_format($product->price, 2) }} TND</h4>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted">Stock</label>
                        <h4>{{ $product->stock }} units</h4>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="text-muted">Description</label>
                    <p>{{ $product->description ?: 'No description provided' }}</p>
                </div>

                <div class="mb-3">
                    <label class="text-muted">SKU</label>
                    <p>{{ $product->sku ?: 'N/A' }}</p>
                </div>

                <div class="mb-3">
                    <label class="text-muted">Added</label>
                    <p>{{ $product->created_at->format('M d, Y H:i') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Seller Info -->
        <div class="card mb-3">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-store"></i> Seller</h5>
            </div>
            <div class="card-body">
                <h6>{{ $product->seller->name }}</h6>
                <p class="text-muted mb-2">{{ $product->seller->email }}</p>
                
                @if($product->seller->is_approved)
                    <span class="badge bg-success">Approved Seller</span>
                @else
                    <span class="badge bg-warning">Seller Not Approved</span>
                @endif
            </div>
        </div>

        <!-- Actions -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-cog"></i> Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    @if(!$product->is_approved && $product->seller->is_approved)
                        <form method="POST" action="{{ route('admin.products.approve', $product) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-check"></i> Approve Product
                            </button>
                        </form>
                    @elseif(!$product->seller->is_approved)
                        <button class="btn btn-secondary w-100" disabled>
                            <i class="fas fa-lock"></i> Seller Must Be Approved
                        </button>
                    @endif

                    @if($product->is_approved)
                        <form method="POST" action="{{ route('admin.products.reject', $product) }}" onsubmit="return confirm('Revoke approval?');">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="fas fa-times"></i> Revoke Approval
                            </button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('admin.products.toggle-status', $product) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-{{ $product->is_active ? 'warning' : 'success' }} w-100">
                            <i class="fas fa-power-off"></i> {{ $product->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.products.destroy', $product) }}" onsubmit="return confirm('Delete this product permanently?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="fas fa-trash"></i> Delete Product
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection