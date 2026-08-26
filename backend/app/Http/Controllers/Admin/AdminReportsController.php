<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FinanceRequest;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class AdminReportsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(29)->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        $orders = Order::withoutGlobalScope(TenantScope::class)
            ->whereBetween('created_at', [$from, $to]);
        $total = (clone $orders)->count();
        $delivered = (clone $orders)->where('status', 'delivered')->count();
        $returned = (clone $orders)->where('status', 'returned')->count();
        $deliveredValue = (int) (clone $orders)->where('status', 'delivered')->sum('price');
        $fees = (int) (clone $orders)->where('status', 'delivered')->sum('fee');

        $statusDistribution = collect(Order::STATUSES)->map(function (string $status) use ($orders): array {
            return ['status' => $status, 'count' => (clone $orders)->where('status', $status)->count()];
        });

        $branches = Branch::withoutGlobalScope(TenantScope::class)
            ->where('is_platform_managed', true)
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'name_ku', 'city', 'cash_balance'])
            ->map(function (Branch $branch) use ($from, $to): array {
                $route = Order::withoutGlobalScope(TenantScope::class)
                    ->whereBetween('created_at', [$from, $to])
                    ->where(fn ($query) => $query->where('origin_branch_id', $branch->id)->orWhere('destination_branch_id', $branch->id));

                return [
                    'id' => $branch->id,
                    'name_ar' => $branch->name_ar,
                    'name_en' => $branch->name_en,
                    'name_ku' => $branch->name_ku,
                    'city' => $branch->city,
                    'orders' => (clone $route)->count(),
                    'delivered' => (clone $route)->where('status', 'delivered')->count(),
                    'returned' => (clone $route)->where('status', 'returned')->count(),
                    'cash_balance' => (int) $branch->cash_balance,
                ];
            });

        $courierIds = User::withoutGlobalScopes()
            ->whereIn('role', ['courier', 'pickup_courier', 'delivery_courier', 'transporter'])
            ->pluck('id');
        $couriers = User::withoutGlobalScopes()
            ->whereIn('id', $courierIds)
            ->with('branch:id,name_ar,name_en,name_ku')
            ->get(['id', 'name', 'role', 'branch_id', 'is_online'])
            ->map(function (User $courier) use ($from, $to): array {
                $assigned = Order::withoutGlobalScope(TenantScope::class)
                    ->whereBetween('created_at', [$from, $to])
                    ->where(fn ($query) => $query
                        ->where('courier_id', $courier->id)
                        ->orWhere('pickup_courier_id', $courier->id)
                        ->orWhere('delivery_courier_id', $courier->id));
                $total = (clone $assigned)->count();
                $complete = (clone $assigned)->where('status', 'delivered')->count();

                return [
                    'id' => $courier->id,
                    'name' => $courier->name,
                    'role' => $courier->role,
                    'branch' => $courier->branch?->name_ar,
                    'online' => (bool) $courier->is_online,
                    'orders' => $total,
                    'delivered' => $complete,
                    'returned' => (clone $assigned)->where('status', 'returned')->count(),
                    'rate' => $total ? (int) round(($complete / $total) * 100) : 0,
                ];
            })
            ->sortByDesc('delivered')
            ->values()
            ->take(12);

        $merchants = User::withoutGlobalScopes()
            ->where('role', 'merchant')
            ->get(['id', 'name', 'shop_name'])
            ->map(function (User $merchant) use ($from, $to): array {
                $merchantOrders = Order::withoutGlobalScope(TenantScope::class)
                    ->whereBetween('created_at', [$from, $to])
                    ->where('merchant_id', $merchant->id);

                return [
                    'id' => $merchant->id,
                    'name' => $merchant->name,
                    'shop_name' => $merchant->shop_name,
                    'orders' => (clone $merchantOrders)->count(),
                    'delivered' => (clone $merchantOrders)->where('status', 'delivered')->count(),
                    'value' => (int) (clone $merchantOrders)->where('status', 'delivered')->sum('price'),
                ];
            })
            ->sortByDesc('orders')
            ->values()
            ->take(12);

        $transactions = Transaction::withoutGlobalScope(TenantScope::class)->whereBetween('created_at', [$from, $to]);

        return Inertia::render('Admin/Reports', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'orders' => $total,
                'delivered' => $delivered,
                'returned' => $returned,
                'delivery_rate' => $total ? (int) round(($delivered / $total) * 100) : 0,
                'delivered_value' => $deliveredValue,
                'fees' => $fees,
                'cash_collected' => (int) (clone $transactions)->where('type', 'collected')->where('direction', 1)->sum('amount'),
                'pending_settlements' => FinanceRequest::withoutGlobalScope(TenantScope::class)->where('status', FinanceRequest::PENDING)->count(),
            ],
            'statuses' => $statusDistribution,
            'branches' => $branches,
            'couriers' => $couriers,
            'merchants' => $merchants,
        ]);
    }
}
