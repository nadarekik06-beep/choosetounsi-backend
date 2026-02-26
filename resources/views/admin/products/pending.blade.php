@extends('admin.layout')

@section('title', 'Pending Products')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-box"></i> Pending Product Approvals</h1>
    <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to All Products
    </a>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product Name</th>
                        <th>Seller</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pendingProducts as $product)
                        <tr>
                            <td>{{ $product->id }}</td>
                            <td>
                                <strong>{{ $product->name }}</strong>
                                @if($product->sku)
                                    <br><small class="text-muted">SKU: {{ $product->sku }}</small>
                                @endif
                            </td>
                            <td>
                                {{ $product->seller->name }}
                                @if(!$product->seller->is_approved)
                                    <br><small class="text-danger">
                                        <i class="fas fa-exclamation-triangle"></i> Seller not approved
                                    </small>
                                @endif
                            </td>
                            <td>{{ number_format($product->price, 2) }} TND</td>
                            <td>
                                <span class="badge bg-info">{{ $product->stock }}</span>
                            </td>
                            <td>{{ $product->created_at->format('M d, Y H:i') }}</td>
                            <td>
                                @if($product->seller->is_approved)
                                    <form method="POST" action="{{ route('admin.products.approve', $product) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                @else
                                    <button type="button" class="btn btn-sm btn-secondary" disabled title="Seller must be approved first">
                                        <i class="fas fa-lock"></i> Locked
                                    </button>
                                @endif
                                
                                <form method="POST" action="{{ route('admin.products.reject', $product) }}" class="d-inline" onsubmit="return confirm('Reject this product?');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-check-circle"></i> No pending product approvals
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-3">
            {{ $pendingProducts->links() }}
        </div>
    </div>
</div>
@endsection