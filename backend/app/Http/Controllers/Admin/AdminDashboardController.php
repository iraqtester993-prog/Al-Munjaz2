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
use App\Services\DashboardBranchFilter;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = today();
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
        // The platform dashboard is intentionally cross-tenant.  Be
        // explicit rather than relying on the logged-in admin lacking a
        // tenant_id; a branch account receives the same visual dashboard,
        // but every aggregate below is constrained before it is read.
        $ordersQuery = $this->ordersFor($scope, $selectedBranchId, $branchFilter);
        $transactionsQuery = $this->transactionsFor($scope, $selectedBranchId, $branchFilter);
        $notificationsQuery = $this->notificationsFor($scope, $selectedBranchId, $branchFilter);
        // Keep the dashboard overview to one aggregate order query. The old
        // implementation ran separate SUM/COUNT queries for every KPI and
        // another query for every day of the weekly chart, which became
        // expensive as the operational tables grew.
        $orderStatsQuery = (clone $ordersQuery)
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(price), 0) as order_value')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN price ELSE 0 END), 0) as delivered_value")
            ->selectRaw('COALESCE(SUM(fee), 0) as fees')
            ->selectRaw('COALESCE(SUM(CASE WHEN date = ? THEN 1 ELSE 0 END), 0) as today_orders', [$today->toDateString()]);

        foreach (Order::STATUSES as $status) {
            $orderStatsQuery->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as status_'.$status,
                [$status],
            );
        }

        $orderStats = $orderStatsQuery->toBase()->first();
        $statusCounts = collect(Order::STATUSES)
            ->mapWithKeys(fn (string $status): array => [
                $status => (int) ($orderStats->{'status_'.$status} ?? 0),
            ])
            ->all();

        $ordersCount = (int) ($orderStats->orders_count ?? 0);
        $orderValue = (int) ($orderStats->order_value ?? 0);
        $deliveredValue = (int) ($orderStats->delivered_value ?? 0);
        // The order fee is the source of truth for the platform's delivery fee,
        // while transactions represent the later accounting movement.
        $fees = (int) ($orderStats->fees ?? 0);
        $merchantCount = $this->usersFor($scope, User::query(), $selectedBranchId, $branchFilter)
            ->where('role', 'merchant')
            ->count();
        $userStats = $this->usersFor($scope, User::query(), $selectedBranchId, $branchFilter)
            ->selectRaw('COUNT(*) as users_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN role = ? THEN 1 ELSE 0 END), 0) as couriers_count',
                ['courier'],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN role = ? AND status = ? AND is_online = ? THEN 1 ELSE 0 END), 0) as online_couriers_count',
                ['courier', 'active', true],
            )
            ->toBase()
            ->first();
        $courierCount = (int) ($userStats->couriers_count ?? 0);
        $onlineCouriers = (int) ($userStats->online_couriers_count ?? 0);

        $merchantBalance = (int) Wallet::query()
            ->whereHas('user', fn (Builder $query) => $this->usersFor($scope, $query, $selectedBranchId, $branchFilter)->where('role', 'merchant'))
            ->sum('balance');
        $courierBudget = (int) Wallet::query()
            ->whereHas('user', fn (Builder $query) => $this->usersFor($scope, $query, $selectedBranchId, $branchFilter)->where('role', 'courier'))
            ->sum('budget');
        $collected = (int) (clone $transactionsQuery)
            ->where('type', 'collected')
            ->where('direction', 1)
            ->sum('amount');

        $weekCounts = (clone $ordersQuery)
            ->whereBetween('date', [$today->copy()->subDays(6)->toDateString(), $today->toDateString()])
            ->selectRaw('date, COUNT(*) as total')
            ->groupBy('date')
            ->pluck('total', 'date')
            ->map(fn ($total) => (int) $total);

        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $week[] = [
                'label' => $d->translatedFormat('D'),
                'count' => (int) ($weekCounts->get($d->toDateString()) ?? 0),
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
                // A cross-branch route can be visible to this branch, but
                // contact/account data for the other endpoint is not.
                'merchant_name' => $this->visibleUserName($o->merchant, $scope) ?? ($scope->hasBranchScope() ? null : $o->tenant?->name),
                'courier_name' => $this->visibleUserName($o->courier, $scope),
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
            // MySQL in production uses ONLY_FULL_GROUP_BY. Grouping by the
            // select alias makes it resolve to users.merchant_user_id after
            // this query is joined, instead of the COALESCE expression.
            ->groupByRaw('COALESCE(merchant_id, created_by)');

        $topMerchants = $this->usersFor($scope, User::query(), $selectedBranchId, $branchFilter)
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

        $todayOrders = (int) ($orderStats->today_orders ?? 0);
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
                'users' => (int) ($userStats->users_count ?? 0),
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
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    public function finance(Request $request)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $transactionsQuery = $this->transactionsFor($scope);
        $transactions = (clone $transactionsQuery)
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
                'settlements' => (clone $transactionsQuery)->where('type', 'settlement')->where('direction', 1)->sum('amount'),
                'withdrawals' => (clone $transactionsQuery)->where('type', 'withdrawal')->sum('amount'),
                'fees' => (clone $transactionsQuery)->where('type', 'commission')->sum('amount'),
                'collected' => (clone $transactionsQuery)->where('type', 'collected')->where('direction', 1)->sum('amount'),
            ],
        ]);
    }

    public function notifications(Request $request)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $notifications = $this->notificationsFor($scope)
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

    /** @return Builder<Order> */
    private function ordersFor(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): Builder
    {
        $query = Order::withoutGlobalScope(TenantScope::class);

        if ($scope->hasBranchScope()) {
            return $scope->restrictOrders($query);
        }

        return $branchFilter->restrictOrders($query, $selectedBranchId);
    }

    /** @return Builder<User> */
    private function usersFor(BranchDashboardScope $scope, Builder $query, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): Builder
    {
        if ($scope->hasBranchScope()) {
            return $scope->restrictUsers($query);
        }

        return $branchFilter->restrictByColumn($query, $selectedBranchId, $query->getModel()->qualifyColumn('branch_id'));
    }

    /** @return Builder<Transaction> */
    private function transactionsFor(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): Builder
    {
        $query = Transaction::withoutGlobalScope(TenantScope::class);

        if ($scope->hasBranchScope()) {
            return $query->where(function (Builder $transactions) use ($scope): void {
                $transactions
                    ->whereHas('user', fn (Builder $users) => $scope->restrictUsers($users))
                    ->orWhereHas('order', fn (Builder $orders) => $scope->restrictOrders($orders));
            });
        }

        if (! $selectedBranchId) {
            return $query;
        }

        return $query->where(function (Builder $transactions) use ($branchFilter, $selectedBranchId): void {
            $transactions
                ->whereHas('user', fn (Builder $users) => $branchFilter->restrictByColumn($users, $selectedBranchId, $users->getModel()->qualifyColumn('branch_id')))
                ->orWhereHas('order', fn (Builder $orders) => $branchFilter->restrictOrders($orders, $selectedBranchId));
        });
    }

    /** @return Builder<Notification> */
    private function notificationsFor(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): Builder
    {
        $query = Notification::withoutGlobalScope(TenantScope::class);

        if ($scope->hasBranchScope()) {
            return $query->whereHas('user', fn (Builder $users) => $scope->restrictUsers($users));
        }

        return $selectedBranchId
            ? $query->whereHas('user', fn (Builder $users) => $branchFilter->restrictByColumn($users, $selectedBranchId, $users->getModel()->qualifyColumn('branch_id')))
            : $query;
    }

    private function visibleUserName(?User $user, BranchDashboardScope $scope): ?string
    {
        if (! $user || ($scope->hasBranchScope() && (int) $user->branch_id !== $scope->branchId())) {
            return null;
        }

        return $user->name;
    }
}
