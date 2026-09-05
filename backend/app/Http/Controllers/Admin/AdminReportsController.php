<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FinanceRequest;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\DashboardBranchFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class AdminReportsController extends Controller
{
    public function index(Request $request, BranchDashboardContext $branchDashboard)
    {
        $scope = $branchDashboard->fromRequest($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(29)->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $canViewFinancialReports = $request->user()->canUseAdminPermission('reports', 'view_financial');

        $orders = Order::withoutGlobalScope(TenantScope::class)
            ->whereBetween('created_at', [$from, $to]);
        $this->restrictOrdersToBranch($orders, $scope, $selectedBranchId, $branchFilter);
        $total = (clone $orders)->count();
        $delivered = (clone $orders)->where('status', 'delivered')->count();
        $returned = (clone $orders)->where('status', 'returned')->count();
        $statusDistribution = collect(Order::STATUSES)->map(function (string $status) use ($orders): array {
            return ['status' => $status, 'count' => (clone $orders)->where('status', $status)->count()];
        });

        $branchColumns = ['id', 'name_ar', 'name_en', 'name_ku', 'city'];
        if ($canViewFinancialReports) {
            $branchColumns[] = 'cash_balance';
        }

        $branches = Branch::withoutGlobalScope(TenantScope::class)
            ->where('is_platform_managed', true)
            ->orderBy('name_ar');
        if ($scope->requiresBranchScope()) {
            $scope->restrict($branches, 'branches.id');
        } else {
            $branchFilter->restrictByColumn($branches, $selectedBranchId, 'branches.id');
        }

        $branches = $branches
            ->get($branchColumns)
            ->map(function (Branch $branch) use ($from, $to, $canViewFinancialReports, $scope, $selectedBranchId, $branchFilter): array {
                $route = Order::withoutGlobalScope(TenantScope::class)
                    ->whereBetween('created_at', [$from, $to]);

                if ($scope->requiresBranchScope() || $selectedBranchId !== null) {
                    $this->restrictOrdersToBranch($route, $scope, $selectedBranchId, $branchFilter);
                } else {
                    $route->where(fn ($query) => $query
                        ->where('origin_branch_id', $branch->id)
                        ->orWhere('destination_branch_id', $branch->id));
                }

                $payload = [
                    'id' => $branch->id,
                    'name_ar' => $branch->name_ar,
                    'name_en' => $branch->name_en,
                    'name_ku' => $branch->name_ku,
                    'city' => $branch->city,
                    'orders' => (clone $route)->count(),
                    'delivered' => (clone $route)->where('status', 'delivered')->count(),
                    'returned' => (clone $route)->where('status', 'returned')->count(),
                ];

                if ($canViewFinancialReports) {
                    $payload['cash_balance'] = (int) $branch->cash_balance;
                }

                return $payload;
            });

        // This report measures direct-order performance. Legacy specialist
        // accounts and branch transporters remain preserved elsewhere, but
        // do not represent a pickup-to-delivery courier performance row.
        $courierQuery = User::withoutGlobalScopes()
            ->where('role', 'courier')
            ->with('branch:id,name_ar,name_en,name_ku');
        if ($scope->requiresBranchScope()) {
            $scope->restrictUsers($courierQuery);
        } else {
            $branchFilter->restrictByColumn($courierQuery, $selectedBranchId, 'users.branch_id');
        }

        $couriers = $courierQuery
            ->get(['id', 'name', 'role', 'branch_id', 'is_online'])
            ->map(function (User $courier) use ($from, $to, $scope, $selectedBranchId, $branchFilter): array {
                $assigned = Order::withoutGlobalScope(TenantScope::class)
                    ->whereBetween('created_at', [$from, $to])
                    ->where('courier_id', $courier->id);
                $this->restrictOrdersToBranch($assigned, $scope, $selectedBranchId, $branchFilter);
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

        $merchantQuery = User::withoutGlobalScopes()
            ->where('role', 'merchant');
        if ($scope->requiresBranchScope()) {
            $scope->restrictUsers($merchantQuery);
        } else {
            $branchFilter->restrictByColumn($merchantQuery, $selectedBranchId, 'users.branch_id');
        }

        $merchants = $merchantQuery
            ->get(['id', 'name', 'shop_name'])
            ->map(function (User $merchant) use ($from, $to, $canViewFinancialReports, $scope, $selectedBranchId, $branchFilter): array {
                $merchantOrders = Order::withoutGlobalScope(TenantScope::class)
                    ->whereBetween('created_at', [$from, $to])
                    ->where('merchant_id', $merchant->id);
                $this->restrictOrdersToBranch($merchantOrders, $scope, $selectedBranchId, $branchFilter);

                $payload = [
                    'id' => $merchant->id,
                    'name' => $merchant->name,
                    'shop_name' => $merchant->shop_name,
                    'orders' => (clone $merchantOrders)->count(),
                    'delivered' => (clone $merchantOrders)->where('status', 'delivered')->count(),
                ];

                if ($canViewFinancialReports) {
                    $payload['value'] = (int) (clone $merchantOrders)->where('status', 'delivered')->sum('price');
                }

                return $payload;
            })
            ->sortByDesc('orders')
            ->values()
            ->take(12);

        $kpis = [
            'orders' => $total,
            'delivered' => $delivered,
            'returned' => $returned,
            'delivery_rate' => $total ? (int) round(($delivered / $total) * 100) : 0,
        ];
        if ($canViewFinancialReports) {
            $transactions = Transaction::withoutGlobalScope(TenantScope::class)->whereBetween('created_at', [$from, $to]);
            $this->restrictTransactionsToBranch($transactions, $scope, $selectedBranchId, $branchFilter);
            $pendingSettlements = FinanceRequest::withoutGlobalScope(TenantScope::class)
                ->where('status', FinanceRequest::PENDING);
            if ($scope->requiresBranchScope()) {
                $scope->restrict($pendingSettlements, 'finance_requests.branch_id');
            } else {
                $branchFilter->restrictByColumn($pendingSettlements, $selectedBranchId, 'finance_requests.branch_id');
            }

            $kpis += [
                'delivered_value' => (int) (clone $orders)->where('status', 'delivered')->sum('price'),
                'fees' => (int) (clone $orders)->where('status', 'delivered')->sum('fee'),
                'cash_collected' => (int) (clone $transactions)->where('type', 'collected')->where('direction', 1)->sum('amount'),
                'pending_settlements' => $pendingSettlements->count(),
            ];
        }

        return Inertia::render('Admin/Reports', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => $kpis,
            'statuses' => $statusDistribution,
            'branches' => $branches,
            'couriers' => $couriers,
            'merchants' => $merchants,
            'canViewFinancialReports' => $canViewFinancialReports,
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    /**
     * @param  Builder<Order>  $orders
     */
    private function restrictOrdersToBranch(Builder $orders, BranchDashboardScope $scope, ?int $selectedBranchId = null, ?DashboardBranchFilter $branchFilter = null): void
    {
        if ($scope->requiresBranchScope()) {
            $scope->restrictOrders($orders);
        } elseif ($selectedBranchId !== null) {
            $branchFilter?->restrictOrders($orders, $selectedBranchId);
        }
    }

    /**
     * Transactions have no direct branch column. Keep the financial KPI in
     * SQL scope through its order, account, or finance-request relationship
     * rather than loading a broad ledger and filtering it in PHP.
     *
     * @param  Builder<Transaction>  $transactions
     */
    private function restrictTransactionsToBranch(Builder $transactions, BranchDashboardScope $scope, ?int $selectedBranchId = null, ?DashboardBranchFilter $branchFilter = null): void
    {
        if (! $scope->requiresBranchScope() && $selectedBranchId === null) {
            return;
        }

        $transactions->where(function (Builder $visible) use ($scope, $selectedBranchId, $branchFilter): void {
            $visible
                ->whereHas('order', function (Builder $orders) use ($scope, $selectedBranchId, $branchFilter): void {
                    $orders->withoutGlobalScope(TenantScope::class);
                    $this->restrictOrdersToBranch($orders, $scope, $selectedBranchId, $branchFilter);
                })
                ->orWhereHas('user', function (Builder $users) use ($scope, $selectedBranchId, $branchFilter): void {
                    $users->withoutGlobalScope(TenantScope::class);
                    if ($scope->requiresBranchScope()) {
                        $scope->restrictUsers($users);
                    } else {
                        $branchFilter?->restrictByColumn($users, $selectedBranchId, 'users.branch_id');
                    }
                })
                ->orWhereHas('financeRequest', function (Builder $requests) use ($scope, $selectedBranchId, $branchFilter): void {
                    $requests->withoutGlobalScope(TenantScope::class);
                    if ($scope->requiresBranchScope()) {
                        $scope->restrict($requests, 'finance_requests.branch_id');
                    } else {
                        $branchFilter?->restrictByColumn($requests, $selectedBranchId, 'finance_requests.branch_id');
                    }
                });
        });
    }
}
