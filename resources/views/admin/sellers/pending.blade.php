@extends('admin.layout')

@section('title', 'Pending Sellers')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-user-clock"></i> Pending Seller Approvals</h1>
    <a href="{{ route('admin.sellers.index') }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to All Sellers
    </a>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Products</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pendingSellers as $seller)
                        <tr>
                            <td>{{ $seller->id }}</td>
                            <td>
                                <strong>{{ $seller->name }}</strong>
                            </td>
                            <td>{{ $seller->email }}</td>
                            <td>
                                <span class="badge bg-info">{{ $seller->products->count() }} products</span>
                            </td>
                            <td>{{ $seller->created_at->format('M d, Y H:i') }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.sellers.approve', $seller) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </form>
                                
                                <form method="POST" action="{{ route('admin.sellers.reject', $seller) }}" class="d-inline" onsubmit="return confirm('Reject this seller?');">
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
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="fas fa-check-circle"></i> No pending seller approvals
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-3">
            {{ $pendingSellers->links() }}
        </div>
    </div>
</div>
@endsection