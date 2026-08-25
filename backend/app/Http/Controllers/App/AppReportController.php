<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AppReportController extends Controller
{
    /**
     * The merchant archive is deliberately calculated from the same scoped
     * order data that powers the dashboard.  It is not a client-side copy of
     * orders, so totals stay correct when an administrator or courier changes
     * an order status.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'merchant', 403);

        $data = $request->validate([
            'period' => ['nullable', Rule::in(['all', 'today'])],
        ]);

        $period = $data['period'] ?? 'all';
        $query = Order::query()->latest('date')->latest('id');

        if ($period === 'today') {
            $query->whereDate('date', today());
        }

        $orders = $query->get();

        $summary = [
            'orders_count' => $orders->count(),
            'orders_value' => (int) $orders->sum('price'),
            'delivered_count' => $orders->where('status', 'delivered')->count(),
            'delivered_value' => (int) $orders->where('status', 'delivered')->sum('price'),
            'returned_count' => $orders->where('status', 'returned')->count(),
            'returned_value' => (int) $orders->where('status', 'returned')->sum('price'),
        ];

        return Inertia::render('Mobile/Reports', [
            'period' => $period,
            'summary' => $summary,
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'track_no' => $order->track_no,
                'customer_name_ar' => $order->customer_name_ar,
                'address_ar' => $order->address_ar,
                'price' => $order->price,
                'status' => $order->status,
                'date' => $order->date->toDateString(),
            ])->all(),
        ]);
    }
}
