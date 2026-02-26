<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Client Dashboard Controller
 * Shows client's order history and account overview
 */
class ClientDashboardController extends Controller
{
    /**
     * Display client dashboard
     */
    public function index()
    {
        $client = auth()->user();

        // Get client statistics
        $statistics = [
            'total_orders' => $client->orders()->count(),
            'pending_orders' => $client->orders()->pending()->count(),
            'completed_orders' => $client->orders()->completed()->count(),
            'total_spent' => $client->orders()->completed()->sum('total_amount'),
        ];

        // Get recent orders
        $recent_orders = $client->orders()
            ->latest()
            ->take(5)
            ->get();

        return view('client.dashboard', compact('statistics', 'recent_orders'));
    }
}