@extends('admin.layout')

@section('title', 'Manage Products')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-box"></i> Manage Products</h1>
    <a href="{{ route('admin.products.pending') }}" class="btn btn-warning">
        <i class="fas fa-clock"></i> Pending Approvals ({{ $stats['pending_products'] }})
    </a>
</div>

<!-- Statistics -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3 class="text-primary">{{ $stats['total_products'] }}</h3>
                <p class="text-muted mb-0">Total Products</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3 class="text-success">{{ $stats['approved_products'] }}</h3>
                <p class="text-muted mb-0">Approved</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3 class="text-warning">{{ $stats['pending_products'] }}</h3>
                <p class="text-muted mb-0">Pending</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3 class="text-info">{{ $stats['active_products'] }}</h3>
                <p class="text-muted mb-0">Active</p>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.products.index') }}">
            <div class="row">
                <div class="col-md-3">
                    <select name="approval" class="form-select">
                        <option value="">All Approval Status</option>
                        <option value="approved" {{ request('approval') == 'approved' ? 'selected' : '' }}>Approved</option>
                        <option value="pending" {{ request('approval') == 'pending' ? 'selected' : '' }}>Pending</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Active Status</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search by name or SKU..." value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>
                            <input type="checkbox" id="select-all">
                        </th>
                        <th>ID</th>
                        <th>Product Name</th>
                        <th>Seller</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Approval</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        <tr>
                            <td>
                                <input type="checkbox" name="product_ids[]" value="{{ $product->id }}">
                            </td>
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
                                    <br><small class="text-danger">Seller not approved</small>
                                @endif
                            </td>
                            <td>{{ number_format($product->price, 2) }} TND</td>
                            <td>
                                @if($product->stock > 10)
                                    <span class="badge bg-success">{{ $product->stock }}</span>
                                @elseif($product->stock > 0)
                                    <span class="badge bg-warning">{{ $product->stock }}</span>
                                @else
                                    <span class="badge bg-danger">Out of Stock</span>
                                @endif
                            </td>
                            <td>
                                @if($product->is_approved)
                                    <span class="badge bg-success">
                                        <i class="fas fa-check"></i> Approved
                                    </span>
                                @else
                                    <span class="badge bg-warning">
                                        <i class="fas fa-clock"></i> Pending
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if($product->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="{{ route('admin.products.show', $product) }}" class="btn btn-sm btn-info" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    @if(!$product->is_approved)
                                        <form method="POST" action="{{ route('admin.products.approve', $product) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-success" title="Approve" {{ !$product->seller->is_approved ? 'disabled' : '' }}>
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    @endif
                                    
                                    <form method="POST" action="{{ route('admin.products.toggle-status', $product) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm {{ $product->is_active ? 'btn-warning' : 'btn-primary' }}" title="{{ $product->is_active ? 'Deactivate' : 'Activate' }}">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="d-inline" onsubmit="return confirm('Are you sure?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="fas fa-inbox"></i> No products found
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Bulk Actions -->
        @if($products->count() > 0)
            <div class="mt-3">
                <form method="POST" action="{{ route('admin.products.bulk-approve') }}" class="d-inline" id="bulk-approve-form">
                    @csrf
                    <button type="submit" class="btn btn-success" disabled id="bulk-approve-btn">
                        <i class="fas fa-check"></i> Approve Selected
                    </button>
                </form>
                
                <form method="POST" action="{{ route('admin.products.bulk-reject') }}" class="d-inline ms-2" id="bulk-reject-form">
                    @csrf
                    <button type="submit" class="btn btn-danger" disabled id="bulk-reject-btn">
                        <i class="fas fa-times"></i> Reject Selected
                    </button>
                </form>
            </div>
        @endif

        <!-- Pagination -->
        <div class="mt-3">
            {{ $products->links() }}
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    // Bulk actions checkbox handling
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('input[name="product_ids[]"]');
    const approveBtn = document.getElementById('bulk-approve-btn');
    const rejectBtn = document.getElementById('bulk-reject-btn');
    const approveForm = document.getElementById('bulk-approve-form');
    const rejectForm = document.getElementById('bulk-reject-form');

    selectAll?.addEventListener('change', function() {
        checkboxes.forEach(cb => cb.checked = this.checked);
        updateBulkButtons();
    });

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkButtons);
    });

    function updateBulkButtons() {
        const checked = Array.from(checkboxes).filter(cb => cb.checked);
        approveBtn.disabled = checked.length === 0;
        rejectBtn.disabled = checked.length === 0;
    }

    // Add selected IDs to form submission
    approveForm?.addEventListener('submit', function(e) {
        const checked = Array.from(checkboxes).filter(cb => cb.checked);
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'product_ids[]';
            input.value = cb.value;
            this.appendChild(input);
        });
    });

    rejectForm?.addEventListener('submit', function(e) {
        const checked = Array.from(checkboxes).filter(cb => cb.checked);
        if (!confirm('This will reject/deactivate ' + checked.length + ' product(s). Continue?')) {
            e.preventDefault();
            return;
        }
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'product_ids[]';
            input.value = cb.value;
            this.appendChild(input);
        });
    });
</script>
@endsection