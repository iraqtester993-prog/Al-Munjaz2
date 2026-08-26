<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = today();
        // The platform dashboard is intentionally cross-tenant.  Be
        // explicit rather than relying on the logged-in admin lacking a
        // tenant_id; this preserves a complete view for future admin setups.
        $ordersQuery = Order::withoutGlobalScope(TenantScope::class);
        $transactionsQuery = Transaction::withoutGlobalScope(TenantScope::class);
        $notificationsQuery = Notification::withoutGlobalScope(TenantScope::class);
        $statusCounts = array_fill_keys(Order::STATUSES, 0);
        $recordedStatuses = (clone $ordersQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();
        $statusCounts = array_replace($statusCounts, $recordedStatuses);

        $ordersCount = (int) array_sum($statusCounts);
        $orderValue = (int) (clone $ordersQuery)->sum('price');
        $deliveredValue = (int) (clone $ordersQuery)->where('status', 'delivered')->sum('price');
        // The order fee is the source of truth for the platform's delivery fee,
        // while transactions represent the later accounting movement.
        $fees = (int) (clone $ordersQuery)->sum('fee');
        $merchantCount = Tenant::query()->where('kind', 'merchant')->count();
        $courierRoles = ['courier', 'pickup_courier', 'delivery_courier', 'transporter'];
        $courierCount = User::query()->whereIn('role', $courierRoles)->count();
        $onlineCouriers = User::query()
            ->whereIn('role', $courierRoles)
            ->where('status', 'active')
            ->where('is_online', true)
            ->count();

        $merchantBalance = (int) Wallet::query()
            ->whereHas('user', fn ($query) => $query->where('role', 'merchant'))
            ->sum('balance');
        $courierBudget = (int) Wallet::query()
            ->whereHas('user', fn ($query) => $query->whereIn('role', $courierRoles))
            ->sum('budget');
        $collected = (int) (clone $transactionsQuery)
            ->where('type', 'collected')
            ->where('direction', 1)
            ->sum('amount');

        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $week[] = [
                'label' => $d->translatedFormat('D'),
                'count' => (clone $ordersQuery)->whereDate('date', $d)->count(),
            ];
        }

        $recentOrders = (clone $ordersQuery)
            ->with(['courier:id,name', 'merchant:id,name,shop_name', 'tenant:id,name'])
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'track_no' => $o->track_no,
                'customer_name_ar' => $o->customer_name_ar,
                'customer_name_en' => $o->customer_name_en,
                'phone' => $o->phone,
                'price' => $o->price,
                'status' => $o->status,
                'date' => $o->date->toDateString(),
                'source' => $o->source,
                'merchant_name' => $o->merchant?->name ?? $o->tenant?->name,
                'courier_name' => $o->courier?->name,
            ]);

        $recentNotifs = (clone $notificationsQuery)->latest('id')->limit(5)->get()->map(fn (Notification $n) => [
            'id' => $n->id,
            'title' => $n->titleFor(),
            'body' => $n->bodyFor(),
            'read' => $n->read_at !== null,
            'time' => $n->created_at->diffForHumans(),
        ]);

        $merchantStats = (clone $ordersQuery)
            ->selectRaw('COALESCE(merchant_id, created_by) as merchant_user_id')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN price ELSE 0 END), 0) as collected")
            ->whereRaw('COALESCE(merchant_id, created_by) IS NOT NULL')
            ->groupBy('merchant_user_id');

        $topMerchants = User::query()
            ->leftJoinSub($merchantStats, 'merchant_stats', function ($join) {
                $join->on('merchant_stats.merchant_user_id', '=', 'users.id');
            })
            ->where('users.role', 'merchant')
            ->select([
                'users.id',
                'users.name',
                'users.phone',
                'users.shop_name',
            ])
            ->selectRaw('COALESCE(merchant_stats.orders_count, 0) as orders_count')
            ->selectRaw('COALESCE(merchant_stats.collected, 0) as collected')
            ->orderByDesc('orders_count')
            ->orderBy('users.name')
            ->limit(4)
            ->get()
            ->map(fn (User $merchant) => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'shop_name' => $merchant->shop_name,
                'phone' => $merchant->phone,
                'orders' => (int) $merchant->orders_count,
                'collected' => (int) $merchant->collected,
            ]);

        $todayOrders = (clone $ordersQuery)->whereDate('date', $today)->count();
        $attentionCount = ($statusCounts['pending'] ?? 0) + ($statusCounts['approved'] ?? 0);
        $deliveryRate = $ordersCount > 0
            ? (int) round((($statusCounts['delivered'] ?? 0) / $ordersCount) * 100)
            : 0;

        return Inertia::render('Admin/Dashboard', [
            'kpis' => [
                'orders' => $ordersCount,
                'pending' => $statusCounts['pending'] ?? 0,
                'courier' => $statusCounts['courier'] ?? 0,
                'delivered' => $statusCounts['delivered'] ?? 0,
                'value' => $orderValue,
                'deliveredValue' => $deliveredValue,
                'fees' => $fees,
                'merchants' => $merchantCount,
                'couriers' => $courierCount,
                'users' => User::query()->count(),
                'unreadNotifs' => (clone $notificationsQuery)->whereNull('read_at')->count(),
            ],
            'operations' => [
                'todayOrders' => $todayOrders,
                'attentionCount' => $attentionCount,
                'onlineCouriers' => $onlineCouriers,
                'deliveryRate' => $deliveryRate,
            ],
            'financials' => [
                'value' => $orderValue,
                'deliveredValue' => $deliveredValue,
                'fees' => $fees,
                'merchantBalance' => $merchantBalance,
                'courierBudget' => $courierBudget,
                'collected' => $collected,
            ],
            'statusCounts' => $statusCounts,
            'week' => $week,
            'recentOrders' => $recentOrders,
            'recentNotifs' => $recentNotifs,
            'topMerchants' => $topMerchants,
        ]);
    }

    public function finance(Request $request)
    {
        $transactions = Transaction::withoutGlobalScope(TenantScope::class)
            ->with('user:id,name')
            ->latest('date')
            ->limit(200)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => $tx->amount,
                'direction' => $tx->direction,
                'ref' => $tx->ref,
                'date' => $tx->date->toDateString(),
                'note' => $tx->note,
                'user' => $tx->user?->name,
            ]);

        return Inertia::render('Admin/Finance', [
            'transactions' => $transactions,
            'summary' => [
                'settlements' => Transaction::withoutGlobalScope(TenantScope::class)->where('type', 'settlement')->where('direction', 1)->sum('amount'),
                'withdrawals' => Transaction::withoutGlobalScope(TenantScope::class)->where('type', 'withdrawal')->sum('amount'),
                'fees' => Transaction::withoutGlobalScope(TenantScope::class)->where('type', 'delivery_fee')->sum('amount'),
                'collected' => Transaction::withoutGlobalScope(TenantScope::class)->where('type', 'collected')->where('direction', 1)->sum('amount'),
            ],
        ]);
    }

    public function notifications(Request $request)
    {
        $notifications = Notification::withoutGlobalScope(TenantScope::class)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->titleFor(),
                'body' => $n->bodyFor(),
                'read' => $n->read_at !== null,
                'time' => $n->created_at->diffForHumans(),
            ]);

        return Inertia::render('Admin/Notifications', ['notifications' => $notifications]);
    }
}
