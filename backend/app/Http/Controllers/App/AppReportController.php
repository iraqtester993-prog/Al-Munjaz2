<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Province;
use App\Services\CourierOrderAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AppReportController extends Controller
{
    /** @var array<int, string> */
    private const ARCHIVE_STATUSES = Order::ARCHIVABLE_STATUSES;

    /**
     * The merchant archive is deliberately calculated from the same scoped
     * order data that powers the dashboard.  It is not a client-side copy of
     * orders, so totals stay correct when an administrator or courier changes
     * an order status.
     */
    public function index(Request $request)
    {
        $viewer = $request->user();
        $isCourier = $viewer->role === 'courier';
        abort_unless($viewer->role === 'merchant' || $isCourier, 403);

        $archiveStatuses = self::ARCHIVE_STATUSES;
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['all', 'today'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            // Only terminal delivery/return work can enter the archive, and
            // it must have been archived manually or by the nightly task.
            'status' => ['nullable', Rule::in(['all', ...$archiveStatuses])],
            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            // Detail rows are loaded only after the merchant opens one of
            // the status cards. Keeping this separate from the report filter
            // lets the overview remain an inexpensive aggregate query.
            'detail_status' => ['nullable', Rule::in($archiveStatuses)],
            'detail_cursor' => ['nullable', 'string', 'max:512'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $period = $data['period'] ?? 'all';
        $filters = [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'status' => $data['status'] ?? 'all',
            'province_id' => isset($data['province_id']) ? (int) $data['province_id'] : null,
            'q' => trim((string) ($data['q'] ?? '')),
        ];
        $query = ($isCourier
            ? app(CourierOrderAccess::class)->assigned($viewer)
            : Order::query())
            ->whereIn('status', $archiveStatuses)
            ->whereNotNull('archived_at');

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
        if ($filters['q'] !== '') {
            $q = $filters['q'];
            $query->where(function ($orders) use ($q, $isCourier): void {
                $orders->where('track_no', 'like', "%{$q}%")
                    ->orWhere('customer_name_ar', 'like', "%{$q}%")
                    ->orWhere('customer_name_en', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhereHas($isCourier ? 'merchant' : 'courier', function ($person) use ($q, $isCourier): void {
                        $person->where('name', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%");
                        if ($isCourier) {
                            $person->orWhere('shop_name', 'like', "%{$q}%");
                        }
                    });
            });
        }

        // The archive may contain years of completed work. Calculate its
        // cards and totals in SQL instead of hydrating every historic order
        // just to count it again in PHP.
        $totals = (clone $query)
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(price), 0) as orders_value')
            ->selectRaw("SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN price ELSE 0 END), 0) as delivered_value")
            ->selectRaw("SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'returned' THEN price ELSE 0 END), 0) as returned_value")
            ->first();

        $provinceRows = (clone $query)
            ->select('province_id')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(price), 0) as amount')
            ->groupBy('province_id')
            ->orderByDesc('orders')
            ->orderBy('province_id')
            ->get();

        $provinceIds = $provinceRows->pluck('province_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $provinceById = Province::query()
            ->whereIn('id', $provinceIds)
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'name_ku'])
            ->keyBy('id');
        $provinceOptions = $provinceById->values();
        $provinceDistribution = $provinceRows
            ->map(function ($row) use ($provinceById) {
                $province = $provinceById->get((int) $row->province_id);

                return [
                    'id' => $province?->id,
                    'name_ar' => $province?->name_ar,
                    'name_en' => $province?->name_en,
                    'name_ku' => $province?->name_ku,
                    'orders' => (int) $row->orders,
                    'amount' => (int) $row->amount,
                ];
            })
            ->values();

        $summary = [
            'orders_count' => (int) $totals->orders_count,
            'orders_value' => (int) $totals->orders_value,
            'delivered_count' => (int) $totals->delivered_count,
            'delivered_value' => (int) $totals->delivered_value,
            'returned_count' => (int) $totals->returned_count,
            'returned_value' => (int) $totals->returned_value,
            'status_counts' => [
                'delivered' => (int) $totals->delivered_count,
                'returned' => (int) $totals->returned_count,
            ],
            'status_values' => [
                'delivered' => (int) $totals->delivered_value,
                'returned' => (int) $totals->returned_value,
            ],
        ];

        $detailStatus = $data['detail_status'] ?? null;
        $detailOrders = collect();
        $orderPagination = [
            'has_more' => false,
            'next_cursor' => null,
        ];

        if ($detailStatus) {
            $detailPage = (clone $query)
                ->where('status', $detailStatus)
                ->with(['province:id,name_ar,name_en,name_ku', 'merchant:id,name,shop_name,phone', 'courier:id,name,phone'])
                ->latest('date')
                ->latest('id')
                ->cursorPaginate(25, ['id', 'track_no', 'customer_name_ar', 'customer_name_en', 'phone', 'price', 'fee', 'status', 'date', 'province_id', 'merchant_id', 'courier_id'], 'detail_cursor');

            $detailOrders = $detailPage->getCollection();
            $orderPagination = [
                'has_more' => $detailPage->hasMorePages(),
                'next_cursor' => $detailPage->nextCursor()?->encode(),
            ];
        }

        return Inertia::render('Mobile/Reports', [
            'period' => $period,
            'filters' => $filters,
            'isCourier' => $isCourier,
            'summary' => $summary,
            'statusOptions' => $archiveStatuses,
            'provinceOptions' => $provinceOptions,
            'provinceDistribution' => $provinceDistribution,
            'detailStatus' => $detailStatus,
            'orderPagination' => $orderPagination,
            'orders' => $detailOrders->map(fn (Order $order) => [
                'id' => $order->id,
                'track_no' => $order->track_no,
                'customer_name_ar' => $order->customer_name_ar,
                'customer_name_en' => $order->customer_name_en,
                'phone' => $order->phone,
                'price' => $order->price,
                'fee' => $order->fee,
                'status' => $order->status,
                'date' => $order->date->toDateString(),
                'province' => $order->province ? [
                    'id' => $order->province->id,
                    'name_ar' => $order->province->name_ar,
                    'name_en' => $order->province->name_en,
                    'name_ku' => $order->province->name_ku,
                ] : null,
                'merchant' => $order->merchant ? ['name' => $order->merchant->shop_name ?: $order->merchant->name] : null,
                'courier' => $order->courier ? ['name' => $order->courier->name] : null,
            ])->all(),
        ]);
    }

}
