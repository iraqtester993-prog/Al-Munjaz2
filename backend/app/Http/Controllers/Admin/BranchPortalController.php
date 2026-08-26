<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Read-only portal data for a branch owner or branch manager.
 *
 * This controller is intentionally separate from the platform-wide dashboard.
 * Its branch list is derived from explicit `branch_memberships` records, not
 * from a request-supplied branch id or the user's legacy `branch_id` alone.
 */
class BranchPortalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && in_array($user->role, ['owner', 'branch_manager'], true),
            403,
        );

        $branches = $this->allowedBranches($user);
        $branchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $metrics = $this->branchMetrics($branchIds);
        $recentOrders = $this->recentOrders($branchIds);

        $branchPayload = $branches->map(function (Branch $branch) use ($metrics, $user): array {
            $metric = $metrics->get($branch->id);
            $membership = $branch->memberships->first();

            return [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $this->branchName($branch),
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'name_ku' => $branch->name_ku,
                'city' => $branch->city,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'is_active' => $branch->is_active,
                'cash_balance' => (int) $branch->cash_balance,
                'access_role' => $membership?->access_role,
                'orders' => [
                    'total' => (int) ($metric?->total_orders ?? 0),
                    'active' => (int) ($metric?->active_orders ?? 0),
                    'delivered' => (int) ($metric?->delivered_orders ?? 0),
                    'today' => (int) ($metric?->today_orders ?? 0),
                ],
            ];
        })->values();

        return Inertia::render('Admin/BranchPortal', [
            'branches' => $branchPayload,
            'recentOrders' => $recentOrders,
            'summary' => [
                'branches' => $branchPayload->count(),
                'activeBranches' => $branchPayload->where('is_active', true)->count(),
                'orders' => (int) $branchPayload->sum('orders.total'),
                'activeOrders' => (int) $branchPayload->sum('orders.active'),
                'deliveredOrders' => (int) $branchPayload->sum('orders.delivered'),
                'todayOrders' => (int) $branchPayload->sum('orders.today'),
            ],
        ]);
    }

    /**
     * @return Collection<int, Branch>
     */
    private function allowedBranches(User $user): Collection
    {
        $requiredAccessRole = $user->role === 'owner'
            ? BranchMembership::OWNER
            : BranchMembership::MANAGER;
        $platformTenantId = Tenant::platform()->id;

        return Branch::withoutGlobalScope(TenantScope::class)
            ->where('branches.tenant_id', $platformTenantId)
            ->where('branches.is_platform_managed', true)
            ->where(function (Builder $eligible) use ($user, $requiredAccessRole): void {
                $eligible->whereHas('memberships', function (Builder $memberships) use ($user, $requiredAccessRole): void {
                    $memberships
                        ->where('user_id', $user->id)
                        ->where('access_role', $requiredAccessRole);
                });
            })
            ->with([
                'memberships' => fn ($memberships) => $memberships
                    ->where('user_id', $user->id)
                    ->where('access_role', $requiredAccessRole),
            ])
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get();
    }

    /**
     * Build metrics with a SQL union so a cross-branch route contributes once
     * to each permitted endpoint but never leaks an unauthorised endpoint.
     *
     * @param array<int, int> $branchIds
     * @return Collection<int, object>
     */
    private function branchMetrics(array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        $origin = DB::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('origin_branch_id', $branchIds)
            ->selectRaw('origin_branch_id as branch_id, status, date');

        $destination = DB::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('destination_branch_id', $branchIds)
            // A route that begins and ends at one branch should contribute
            // once, while a genuine cross-branch route belongs to both.
            ->where(function ($orders): void {
                $orders
                    ->whereNull('origin_branch_id')
                    ->orWhereColumn('origin_branch_id', '!=', 'destination_branch_id');
            })
            ->selectRaw('destination_branch_id as branch_id, status, date');

        $legacy = DB::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('branch_id', $branchIds)
            // Older orders only have `branch_id`. Do not double-count it if
            // the modern route fields already point at the same branch.
            ->where(function ($orders): void {
                $orders
                    ->whereNull('origin_branch_id')
                    ->orWhereColumn('origin_branch_id', '!=', 'branch_id');
            })
            ->where(function ($orders): void {
                $orders
                    ->whereNull('destination_branch_id')
                    ->orWhereColumn('destination_branch_id', '!=', 'branch_id');
            })
            ->selectRaw('branch_id as branch_id, status, date');

        $terminalPlaceholders = implode(', ', array_fill(0, count(Order::TERMINAL_STATUSES), '?'));
        $links = $origin->unionAll($destination)->unionAll($legacy);

        return DB::query()
            ->fromSub($links, 'branch_order_links')
            ->select('branch_id')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw(
                "SUM(CASE WHEN status NOT IN ({$terminalPlaceholders}) THEN 1 ELSE 0 END) as active_orders",
                Order::TERMINAL_STATUSES,
            )
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered_orders', ['delivered'])
            ->selectRaw('SUM(CASE WHEN date = ? THEN 1 ELSE 0 END) as today_orders', [today()->toDateString()])
            ->groupBy('branch_id')
            ->get()
            ->keyBy(fn (object $row) => (int) $row->branch_id);
    }

    /**
     * @param array<int, int> $branchIds
     * @return Collection<int, array<string, mixed>>
     */
    private function recentOrders(array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return Order::withoutGlobalScope(TenantScope::class)
            ->where(function (Builder $orders) use ($branchIds): void {
                $orders
                    ->whereIn('origin_branch_id', $branchIds)
                    ->orWhereIn('destination_branch_id', $branchIds)
                    ->orWhereIn('branch_id', $branchIds);
            })
            ->with([
                'originBranch:id,code,name_ar,name_en,name_ku',
                'destinationBranch:id,code,name_ar,name_en,name_ku',
                'branch:id,code,name_ar,name_en,name_ku',
            ])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(function (Order $order) use ($branchIds): array {
                $visibleBranches = collect([
                    $order->originBranch,
                    $order->destinationBranch,
                    $order->branch,
                ])
                    ->filter(fn (?Branch $branch) => $branch && in_array((int) $branch->id, $branchIds, true))
                    ->unique('id')
                    ->map(fn (Branch $branch) => [
                        'id' => $branch->id,
                        'code' => $branch->code,
                        'name' => $this->branchName($branch),
                    ])
                    ->values();

                return [
                    'id' => $order->id,
                    'track_no' => $order->track_no,
                    'customer_name' => $order->customer_name_ar ?: $order->customer_name_en,
                    'status' => $order->status,
                    'workflow_stage' => $order->workflow_stage,
                    'price' => (int) $order->price,
                    'date' => $order->date?->toDateString(),
                    'branches' => $visibleBranches,
                ];
            })
            ->values();
    }

    private function branchName(Branch $branch): string
    {
        return $branch->name_ar ?: $branch->name_en ?: $branch->name_ku ?: $branch->code ?: __('Unnamed branch');
    }
}
