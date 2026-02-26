@extends('admin.layout')

@section('title', 'Manage Sellers')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-store"></i> Manage Sellers</h1>
    <a href="{{ route('admin.sellers.pending') }}" class="btn btn-warning">
        <i class="fas fa-clock"></i> Pending Approvals ({{ $stats['pending_sellers'] }})
    </a>
</div>

<!-- Statistics -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h3 class="text-primary">{{ $stats['total_sellers'] }}</h3>
                <p class="text-muted mb-0">Total Sellers</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h3 class="text-success">{{ $stats['approved_sellers'] }}</h3>
                <p class="text-muted mb-0">Approved Sellers</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h3 class="text-warning">{{ $stats['pending_sellers'] }}</h3>
                <p class="text-muted mb-0">Pending Approval</p>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.sellers.index') }}">
            <div class="row">
                <div class="col-md-4">
                    <select name="status" class="form-select">
                        <option value="">All Sellers</option>
                        <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>Approved</option>
                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Search by name or email..." value="{{ request('search') }}">
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

<!-- Sellers Table -->
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
                        <th>Name</th>
                        <th>Email</th>
                        <th>Products</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sellers as $seller)
                        <tr>
                            <td>
                                <input type="checkbox" name="seller_ids[]" value="{{ $seller->id }}">
                            </td>
                            <td>{{ $seller->id }}</td>
                            <td>
                                <strong>{{ $seller->name }}</strong>
                            </td>
                            <td>{{ $seller->email }}</td>
                            <td>
                                <span class="badge bg-info">{{ $seller->products->count() }} products</span>
                            </td>
                            <td>
                                @if($seller->is_approved)
                                    <span class="badge bg-success">
                                        <i class="fas fa-check"></i> Approved
                                    </span>
                                @else
                                    <span class="badge bg-warning">
                                        <i class="fas fa-clock"></i> Pending
                                    </span>
                                @endif
                            </td>
                            <td>{{ $seller->created_at->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="{{ route('admin.sellers.show', $seller) }}" class="btn btn-sm btn-info" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    @if(!$seller->is_approved)
                                        <form method="POST" action="{{ route('admin.sellers.approve', $seller) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-success" title="Approve">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.sellers.reject', $seller) }}" class="d-inline" onsubmit="return confirm('This will deactivate all their products. Continue?');">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-danger" title="Revoke">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="fas fa-inbox"></i> No sellers found
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Bulk Actions -->
        @if($sellers->count() > 0)
            <div class="mt-3">
                <form method="POST" action="{{ route('admin.sellers.bulk-approve') }}" class="d-inline" id="bulk-approve-form">
                    @csrf
                    <button type="submit" class="btn btn-success" disabled id="bulk-approve-btn">
                        <i class="fas fa-check"></i> Approve Selected
                    </button>
                </form>
                
                <form method="POST" action="{{ route('admin.sellers.bulk-reject') }}" class="d-inline ms-2" id="bulk-reject-form">
                    @csrf
                    <button type="submit" class="btn btn-danger" disabled id="bulk-reject-btn">
                        <i class="fas fa-times"></i> Reject Selected
                    </button>
                </form>
            </div>
        @endif

        <!-- Pagination -->
        <div class="mt-3">
            {{ $sellers->links() }}
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    // Bulk actions checkbox handling
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('input[name="seller_ids[]"]');
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
            input.name = 'seller_ids[]';
            input.value = cb.value;
            this.appendChild(input);
        });
    });

    rejectForm?.addEventListener('submit', function(e) {
        const checked = Array.from(checkboxes).filter(cb => cb.checked);
        if (!confirm('This will revoke approval for ' + checked.length + ' seller(s). Continue?')) {
            e.preventDefault();
            return;
        }
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'seller_ids[]';
            input.value = cb.value;
            this.appendChild(input);
        });
    });
</script>
@endsection