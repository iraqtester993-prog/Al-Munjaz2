<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Province;
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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(array_merge(['all'], Order::STATUSES))],
            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
        ]);

        $period = $data['period'] ?? 'all';
        $filters = [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'status' => $data['status'] ?? 'all',
            'province_id' => isset($data['province_id']) ? (int) $data['province_id'] : null,
        ];
        $query = Order::query()->with('province')->latest('date')->latest('id');

        if ($period === 'today') {
            $query->whereDate('date', today());
        }
        if ($filters['from']) {
            $query->whereDate('date', '>=', $filters['from']);
        }
        if ($filters['to']) {
            $query->whereDate('date', '<=', $filters['to']);
        }
        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if ($filters['province_id']) {
            $query->where('province_id', $filters['province_id']);
        }

        $orders = $query->get();

        $statusCounts = collect(Order::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => $orders->where('status', $status)->count()])
            ->all();
        $statusValues = collect(Order::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => (int) $orders->where('status', $status)->sum('price')])
            ->all();
        $provinceIds = $orders->pluck('province_id')->filter()->unique()->values();
        $provinceOptions = Province::query()
            ->whereIn('id', $provinceIds)
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'name_ku'])
            ->values();
        $provinceDistribution = $orders
            ->groupBy('province_id')
            ->map(function ($provinceOrders, $provinceId) use ($provinceOptions) {
                $province = $provinceOptions->firstWhere('id', (int) $provinceId);

                return [
                    'id' => $province?->id,
                    'name_ar' => $province?->name_ar,
                    'name_en' => $province?->name_en,
                    'name_ku' => $province?->name_ku,
                    'orders' => $provinceOrders->count(),
                    'amount' => (int) $provinceOrders->sum('price'),
                ];
            })
            ->sortByDesc('orders')
            ->values();

        $summary = [
            'orders_count' => $orders->count(),
            'orders_value' => (int) $orders->sum('price'),
            'delivered_count' => $orders->where('status', 'delivered')->count(),
            'delivered_value' => (int) $orders->where('status', 'delivered')->sum('price'),
            'returned_count' => $orders->where('status', 'returned')->count(),
            'returned_value' => (int) $orders->where('status', 'returned')->sum('price'),
            'status_counts' => $statusCounts,
            'status_values' => $statusValues,
        ];

        return Inertia::render('Mobile/Reports', [
            'period' => $period,
            'filters' => $filters,
            'summary' => $summary,
            'statusOptions' => Order::STATUSES,
            'provinceOptions' => $provinceOptions,
            'provinceDistribution' => $provinceDistribution,
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'track_no' => $order->track_no,
                'customer_name_ar' => $order->customer_name_ar,
                'customer_name_en' => $order->customer_name_en,
                'address_ar' => $order->address_ar,
                'address_en' => $order->address_en,
                'price' => $order->price,
                'status' => $order->status,
                'date' => $order->date->toDateString(),
                'province' => $order->province ? [
                    'id' => $order->province->id,
                    'name_ar' => $order->province->name_ar,
                    'name_en' => $order->province->name_en,
                    'name_ku' => $order->province->name_ku,
                ] : null,
            ])->all(),
        ]);
    }
}
