@extends('admin.layout')

@section('title', 'Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-tachometer-alt"></i> Admin Dashboard</h1>
    <div class="text-muted">
        Welcome, <strong>{{ auth()->user()->name }}</strong>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <!-- Total Users -->
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Users</h6>
                        <h2 class="mb-0">{{ $statistics['total_users'] }}</h2>
                        <small class="text-success">
                            <i class="fas fa-arrow-up"></i> {{ $statistics['active_users'] }} active
                        </small>
                    </div>
                    <div class="text-primary" style="font-size: 2.5rem;">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Sellers -->
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Sellers</h6>
                        <h2 class="mb-0">{{ $statistics['total_sellers'] }}</h2>
                        <small class="text-warning">
                            <i class="fas fa-clock"></i> {{ $statistics['pending_sellers'] }} pending
                        </small>
                    </div>
                    <div class="text-success" style="font-size: 2.5rem;">
                        <i class="fas fa-store"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Products -->
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Products</h6>
                        <h2 class="mb-0">{{ $statistics['total_products'] }}</h2>
                        <small class="text-warning">
                            <i class="fas fa-clock"></i> {{ $statistics['pending_products'] }} pending
                        </small>
                    </div>
                    <div class="text-info" style="font-size: 2.5rem;">
                        <i class="fas fa-box"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Orders -->
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Orders</h6>
                        <h2 class="mb-0">{{ $statistics['total_orders'] }}</h2>
                        <small class="text-success">
                            <i class="fas fa-dollar-sign"></i> {{ number_format($statistics['total_revenue'], 2) }} TND
                        </small>
                    </div>
                    <div class="text-warning" style="font-size: 2.5rem;">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Pending Approvals -->
<div class="row">
    <!-- Pending Sellers -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-user-clock"></i> Pending Sellers</h5>
                    <a href="{{ route('admin.sellers.pending') }}" class="btn btn-sm btn-primary">View All</a>
                </div>
            </div>
            <div class="card-body">
                @if($pending_seller_approvals->count() > 0)
                    <div class="list-group">
                        @foreach($pending_seller_approvals as $seller)
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0">{{ $seller->name }}</h6>
                                    <small class="text-muted">{{ $seller->email }}</small>
                                </div>
                                <div>
                                    <form method="POST" action="{{ route('admin.sellers.approve', $seller) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.sellers.reject', $seller) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-center text-muted mb-0">
                        <i class="fas fa-check-circle"></i> No pending seller approvals
                    </p>
                @endif
            </div>
        </div>
    </div>

    <!-- Pending Products -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-box"></i> Pending Products</h5>
                    <a href="{{ route('admin.products.pending') }}" class="btn btn-sm btn-primary">View All</a>
                </div>
            </div>
            <div class="card-body">
                @if($pending_product_approvals->count() > 0)
                    <div class="list-group">
                        @foreach($pending_product_approvals as $product)
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0">{{ $product->name }}</h6>
                                    <small class="text-muted">By: {{ $product->seller->name }} | {{ number_format($product->price, 2) }} TND</small>
                                </div>
                                <div>
                                    <form method="POST" action="{{ route('admin.products.approve', $product) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.products.reject', $product) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-center text-muted mb-0">
                        <i class="fas fa-check-circle"></i> No pending product approvals
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Quick Stats -->
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Quick Statistics</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h3 class="text-success">{{ $statistics['approved_sellers'] }}</h3>
                        <p class="text-muted">Approved Sellers</p>
                    </div>
                    <div class="col-md-3">
                        <h3 class="text-primary">{{ $statistics['approved_products'] }}</h3>
                        <p class="text-muted">Approved Products</p>
                    </div>
                    <div class="col-md-3">
                        <h3 class="text-info">{{ $statistics['completed_orders'] }}</h3>
                        <p class="text-muted">Completed Orders</p>
                    </div>
                    <div class="col-md-3">
                        <h3 class="text-warning">{{ $statistics['pending_orders'] }}</h3>
                        <p class="text-muted">Pending Orders</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection