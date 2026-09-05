<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\IraqiMobilePhone;
use App\Services\DashboardBranchFilter;
use App\Services\OrderOperationalAssignmentService;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\OrderPickupRecoveryService;
use App\Services\OrderWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        // Keep this controller safe even when it is exercised outside the
        // normal dashboard route group: a broken branch account must never
        // fall through to the super-admin's unfiltered query path.
        if ($scope->requiresBranchScope()) {
            abort_unless($scope->hasBranchScope(), 403);
        }
        $branchFilter = app(DashboardBranchFilter::class);
        // This is a presentation filter for the super admin only. A branch
        // dashboard account receives its primary branch id here regardless
        // of any client-controlled `branch_id` query parameter.
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
        $canEditOrders = $user->canUseAdminPermission('orders', 'edit');
        // A dispatcher can follow an order without learning its COD value or
        // the platform fee. An editor does need the current value in order to
        // make the explicitly permitted edit, but aggregate financial cards
        // remain behind their own read capability.
        $canViewOrderFinancialDetails = $canEditOrders
            || $user->canUseAdminPermission('orders', 'view_financial');
        $canViewOrderFinancialSummary = $user->canUseAdminPermission('orders', 'view_financial');
        $canChangeOrderStatus = $user->canUseAdminPermission('orders', 'change_status');
        $canAssignCourier = $user->canUseAdminPermission('orders', 'assign_courier');
        $canReofferOverduePickup = $user->canUseAdminPermission('orders', 'reoffer_overdue_pickup');
        // A branch may work an order that reaches its endpoint, but it never
        // gets to rewrite the network route and expose another branch.
        $canAssignBranches = ! $scope->hasBranchScope()
            && $user->canUseAdminPermission('orders', 'assign_branches');
        $canRestoreOrders = $user->canUseAdminPermission('orders', 'restore');
        $canDeleteOrders = $user->canUseAdminPermission('orders', 'delete');
        // Kept as a compatibility prop for deployed clients. New controls use
        // the action-specific flags below and the server routes enforce them.
        $canUpdateOrders = $canEditOrders
            || $canChangeOrderStatus
            || $canAssignCourier
            || $canReofferOverduePickup
            || $canAssignBranches
            || $canRestoreOrders
            || $canDeleteOrders;
        $rules = [
            'filter' => ['nullable', Rule::in(array_merge(['all', 'deleted'], Order::FILTERABLE_STATUSES))],
            'q' => ['nullable', 'string', 'max:120'],
            // Date-only values intentionally expand to complete local days so
            // an operator's “to” date includes every order created that day.
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Keep the large, explicit "all" mode opt-in. The dashboard
            // normally returns a small page so opening Orders does not load
            // the whole operations history by accident.
            'per_page' => ['nullable', 'string', Rule::in(['25', '50', '100', 'all'])],
            // The list is deliberately small.  These two JSON modes load a
            // single order's operational history or a management directory
            // only after an operator explicitly opens the relevant control.
            'detail' => ['nullable', 'integer', 'min:1'],
            'directory' => ['nullable', Rule::in(['courier_filters', 'assignment'])],
            'assignment_for' => [
                Rule::requiredIf(fn () => $request->input('directory') === 'assignment'),
                'nullable',
                'integer',
                'min:1',
            ],
        ];

        // Courier filtering is backed by the assignment directory below.
        // A view-only operator can inspect orders, but must not use a hidden
        // query parameter as an alternate way to inspect that directory.
        if ($canAssignCourier) {
            $rules['courier_id'] = [
                // This is deliberately limited to direct-order roles. A branch
                // transporter can never be used as a hidden filter to inspect
                // unrelated order data through the operations dashboard.
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(function ($users) use ($scope, $selectedBranchId) {
                    $users
                        ->whereIn('role', User::DIRECT_ORDER_COURIER_ROLES)
                        ->whereNull('deleted_at');

                    if ($scope->hasBranchScope()) {
                        $users->where('branch_id', $scope->branchId());
                    } elseif ($selectedBranchId) {
                        $users->where('branch_id', $selectedBranchId);
                    }
                }),
            ];
        }

        $filters = $request->validate($rules);

        if ($request->filled('detail')) {
            abort_unless($request->expectsJson(), 406);

            return response()->json([
                'order' => $this->detailPayloadFor(
                    $request->integer('detail'),
                    $canViewOrderFinancialDetails,
                    $scope,
                    $selectedBranchId,
                    $branchFilter,
                ),
            ]);
        }

        if ($request->filled('directory')) {
            abort_unless($request->expectsJson(), 406);
            $directory = $request->string('directory')->toString();
            abort_unless(
                $directory === 'courier_filters'
                    ? $canAssignCourier
                    : ($canAssignCourier || $canAssignBranches),
                403,
            );

            return response()->json($this->directoryPayload(
                $directory,
                $request->integer('assignment_for'),
                $canAssignCourier,
                $canAssignBranches,
                $scope,
                $selectedBranchId,
                $branchFilter,
            ));
        }

        // The operations dashboard serves the whole platform, including
        // merchant-owned orders and the shared branch network.
        $allOrders = $this->filteredOrders($scope, $selectedBranchId, $branchFilter);
        $filter = $request->input('filter', 'all');
        $showDeleted = $filter === 'deleted';

        // Deleted orders are never silently discarded. They remain available
        // to platform administration for review and a one-click restore.
        $query = $showDeleted
            ? (clone $allOrders)->onlyTrashed()
            : clone $allOrders;
        // Legacy split-assignment relations are loaded solely to provide one
        // historical courier fallback. They are never exposed as separate
        // operational roles in the current dashboard payload.
        // Table rows contain just the data required to draw the table and
        // operate its controls. A full status/audit timeline for 25 orders
        // made the first dashboard response grow with every historic event.
        // It is now fetched only by the detail request above.
        $query = $query
            ->select($this->summaryColumns($canViewOrderFinancialDetails))
            ->with([
                'courier:id,branch_id,name,phone,vehicle',
                'pickupCourier:id,branch_id,name,phone,vehicle',
                'deliveryCourier:id,branch_id,name,phone,vehicle',
                'merchant:id,branch_id,name,phone,shop_name,address',
                'tenant:id,name',
                'province:id,name_ar,name_en,name_ku',
                'originBranch:id,name_ar,name_en,name_ku,city',
                'destinationBranch:id,name_ar,name_en,name_ku,city',
            ]);

        if ($filter !== 'all' && ! $showDeleted) {
            $query->operationalStatus($filter);
        }

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $query->where(function ($b) use ($q) {
                $b->where('track_no', 'like', "%{$q}%")
                    ->orWhere('customer_name_ar', 'like', "%{$q}%")
                    ->orWhere('customer_name_en', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhereHas('courier', fn ($courier) => $courier
                        ->where(fn ($match) => $match
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%")))
                    ->orWhereHas('pickupCourier', fn ($courier) => $courier
                        ->where(fn ($match) => $match
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%")))
                    ->orWhereHas('deliveryCourier', fn ($courier) => $courier
                        ->where(fn ($match) => $match
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%")));
            });
        }

        $courierId = $canAssignCourier ? $request->integer('courier_id') : null;

        if ($courierId) {
            // The picker shows normal couriers only. Include the retired
            // links here solely so that a courier's historical records stay
            // discoverable after the single-courier migration.
            $query->where(function ($assignments) use ($courierId) {
                $assignments
                    ->where('courier_id', $courierId)
                    ->orWhere('pickup_courier_id', $courierId)
                    ->orWhere('delivery_courier_id', $courierId);
            });
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $filters['to'])->endOfDay());
        }

        $perPage = $this->perPageFor($request);
        $isShowingAll = $perPage === 'all';

        // A LengthAwarePaginator is preserved even for "all" so the page
        // keeps its existing orders.data / total / current_page contract.
        // Force the first (and only) page in this mode: a stale `page=2`
        // query string must never turn an explicit "show all" request into
        // an empty result.
        $pageSize = $isShowingAll
            ? max(1, (clone $query)->count())
            : $perPage;
        $orders = $query->latest('id')->paginate(
            $pageSize,
            ['*'],
            'page',
            $isShowingAll ? 1 : null,
        )->withQueryString();
        $orders->through(fn (Order $order) => $this->summaryPayload($order, $canViewOrderFinancialDetails, $scope));

        // Keep the cards independent from the currently selected table
        // filter/search. They are a platform-wide navigation summary for the
        // same authorised dashboard scope, rather than a second rendering of
        // the current paginated result set.
        $summary = $this->summaryFor($allOrders, $canViewOrderFinancialSummary);
        $counts = $this->countsFromSummary($summary);

        return Inertia::render('Admin/Orders', [
            'orders' => $orders,
            'counts' => $counts,
            'summary' => $summary,
            'filter' => $request->input('filter', 'all'),
            'q' => $q,
            'courierId' => $courierId ?: null,
            'fromDate' => $filters['from'] ?? null,
            'toDate' => $filters['to'] ?? null,
            // The paginator's numeric `per_page` remains compatible for
            // consumers of `orders`; this prop records the selected UI mode
            // and can therefore represent the explicit `all` value.
            'perPage' => $perPage,
            'branchFilter' => $branchFilter->payload($request, $scope),
            'canUpdateOrders' => $canUpdateOrders,
            'canViewOrderFinancialDetails' => $canViewOrderFinancialDetails,
            'canViewOrderFinancialSummary' => $canViewOrderFinancialSummary,
            'canEditOrders' => $canEditOrders,
            'canChangeOrderStatus' => $canChangeOrderStatus,
            'canAssignCourier' => $canAssignCourier,
            'canReofferOverduePickup' => $canReofferOverduePickup,
            'canAssignBranches' => $canAssignBranches,
            'canRestoreOrders' => $canRestoreOrders,
            'canDeleteOrders' => $canDeleteOrders,
        ]);
    }

    /** @return 25|50|100|'all' */
    private function perPageFor(Request $request): int|string
    {
        $value = (string) $request->input('per_page', '25');

        return $value === 'all' ? 'all' : (int) $value;
    }

    /**
     * @return array<int, string>
     */
    private function summaryColumns(bool $includeFinancial): array
    {
        $columns = [
            'id',
            'track_no',
            'source',
            'status',
            'workflow_stage',
            'tenant_id',
            'merchant_id',
            'courier_id',
            'pickup_courier_id',
            'delivery_courier_id',
            'province_id',
            // This is used only to decide whether the current branch may
            // operate the row. It is intentionally never serialised as a
            // raw branch identifier to another branch.
            'branch_id',
            'origin_branch_id',
            'destination_branch_id',
            'customer_name_ar',
            'customer_name_en',
            'phone',
            'address_ar',
        ];

        if ($includeFinancial) {
            $columns[] = 'price';
            $columns[] = 'fee';
        }

        return array_merge($columns, [
            'date',
            'pickup_deadline_at',
            'deleted_at',
        ]);
    }

    /**
     * Keep the initial table payload intentionally shallow. Full contact,
     * route history and audit data are returned by detailPayloadFor. Monetary
     * fields need a distinct read action (or the explicit edit action).
     */
    private function summaryPayload(Order $order, bool $includeFinancial, BranchDashboardScope $scope): array
    {
        $payload = [
            'id' => $order->id,
            'track_no' => $order->track_no,
            'source' => $order->source,
            'status' => $order->status,
            'workflow_stage' => $order->workflow_stage,
            'can_operate' => $this->canOperateOrder($order, $scope),
            'tenant_id' => $this->visibleUser($order->merchant, $scope) || ! $scope->hasBranchScope()
                ? $order->tenant_id
                : null,
            'customer' => [
                'name' => $order->customer_name_ar,
                'name_en' => $order->customer_name_en,
                'phone' => $order->phone,
                'address' => $order->address_ar,
            ],
            'customer_name_ar' => $order->customer_name_ar,
            'phone' => $order->phone,
            'address_ar' => $order->address_ar,
            'date' => $order->date?->toDateString(),
            'deleted_at' => $this->iso($order->deleted_at),
            'pickup_deadline_at' => $this->iso($order->pickup_deadline_at),
            'province_id' => $order->province_id,
            'province' => $order->province ? [
                'id' => $order->province->id,
                'name_ar' => $order->province->name_ar,
                'name_en' => $order->province->name_en,
                'name_ku' => $order->province->name_ku,
            ] : null,
            'tenant' => $this->visibleUser($order->merchant, $scope) ? $order->tenant?->name : null,
            'merchant' => $this->visibleUser($order->merchant, $scope) ? [
                'id' => $order->merchant->id,
                'name' => $order->merchant->name,
                'shop_name' => $order->merchant->shop_name,
            ] : (! $scope->hasBranchScope() && $order->tenant ? [
                'id' => null,
                'name' => $order->tenant->name,
                'shop_name' => null,
            ] : null),
            'origin_branch_id' => $this->visibleBranchId($order->origin_branch_id, $scope),
            'destination_branch_id' => $this->visibleBranchId($order->destination_branch_id, $scope),
            'origin_branch' => $this->branchPayload($order->originBranch, $scope),
            'destination_branch' => $this->branchPayload($order->destinationBranch, $scope),
            // New direct orders use courier_id only. Old records can retain
            // a specialist link, but the dashboard renders it as the same
            // single courier rather than reviving the old split workflow.
            'courier_id' => $this->visibleUser($this->operationalCourier($order), $scope)
                ? $order->courier_id
                : null,
            'courier' => $this->personPayload($this->operationalCourier($order), $scope),
        ];

        if ($includeFinancial) {
            $fee = $order->fee === null ? null : (int) $order->fee;
            $payload['price'] = (int) $order->price;
            $payload['fee'] = $fee;
            $payload['financial'] = [
                'order_value' => (int) $order->price,
                'delivery_fee' => $fee,
            ];
        }

        return $payload;
    }

    /**
     * A detail request is served by the same protected dashboard route. Its
     * financial fields must use the same granular rule as the table, so the
     * JSON endpoint cannot bypass the browser's conditional presentation.
     */
    private function detailPayloadFor(
        int $orderId,
        bool $includeFinancial,
        BranchDashboardScope $scope,
        ?int $selectedBranchId,
        DashboardBranchFilter $branchFilter,
    ): array
    {
        $order = $this->filteredOrders($scope, $selectedBranchId, $branchFilter)
            ->withTrashed()
            ->with([
                'courier:id,branch_id,name,phone,vehicle',
                'pickupCourier:id,branch_id,name,phone,vehicle',
                'deliveryCourier:id,branch_id,name,phone,vehicle',
                'merchant:id,branch_id,name,phone,shop_name,address',
                'tenant:id,name',
                'province:id,name_ar,name_en,name_ku',
                'originBranch:id,name_ar,name_en,name_ku,city',
                'destinationBranch:id,name_ar,name_en,name_ku,city',
                'statusLogs' => fn ($logs) => $logs
                    ->with('user:id,branch_id,name,role')
                    ->latest('created_at'),
                'movements' => fn ($movements) => $movements
                    ->withoutGlobalScope(TenantScope::class)
                    ->with('actor:id,branch_id,name,role')
                    ->latest('occurred_at'),
            ])
            ->findOrFail($orderId);

        // Movements retain the branch that was selected at that point in the
        // route. Resolve those historical branches only for this one detail
        // sheet, rather than for every row of the table.
        $movementBranchIds = $order->movements
            ->flatMap(fn (OrderMovement $movement) => [$movement->from_branch_id, $movement->to_branch_id])
            ->filter()
            ->unique()
            ->values();

        $movementBranches = $movementBranchIds->isEmpty()
            ? collect()
            : Branch::withoutGlobalScope(TenantScope::class)
                ->withTrashed()
                ->whereIn('id', $movementBranchIds)
                ->when($scope->hasBranchScope(), fn (Builder $branches) => $branches->whereKey($scope->branchId()))
                ->get(['id', 'name_ar', 'name_en', 'name_ku', 'city'])
                ->keyBy('id');

        return $this->orderPayload($order, $movementBranches, $includeFinancial, $scope);
    }

    /**
     * Build the status-card payload for the orders dashboard. Operational
     * viewers receive counts only; monetary totals require orders.view_financial.
     *
     * `amount` is deliberately the gross order total (price + delivery fee),
     * matching the total shown in an order's financial details. The component
     * can also use the separate parts for a richer presentation without
     * re-aggregating the current page in the browser.
     *
     * @param  Builder<Order>  $allOrders
     * @return array<string, array{count: int, amount?: int, order_value?: int, delivery_fee?: int}>
     */
    private function summaryFor(Builder $allOrders, bool $includeFinancial): array
    {
        if (! $includeFinancial) {
            return $this->operationalSummaryFor($allOrders);
        }

        $summary = [
            'all' => $this->financialAggregate($allOrders),
        ];

        foreach (Order::STATUSES as $status) {
            $summary[$status] = $this->emptyFinancialAggregate();
        }

        // Group persisted statuses in one query. `late` is intentionally not
        // included here because it is an operational deadline condition, not
        // a stored status value.
        $byStatus = (clone $allOrders)
            ->selectRaw(<<<'SQL'
                status,
                COUNT(*) AS summary_count,
                COALESCE(SUM(COALESCE(price, 0)), 0) AS summary_order_value,
                COALESCE(SUM(COALESCE(fee, 0)), 0) AS summary_delivery_fee,
                COALESCE(SUM(COALESCE(price, 0) + COALESCE(fee, 0)), 0) AS summary_amount
            SQL)
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        foreach (Order::STATUSES as $status) {
            $summary[$status] = $this->financialAggregatePayload($byStatus->get($status));
        }

        // Deleted orders never contribute to the live total. They remain a
        // separate review card with their own gross monetary amount.
        $summary['late'] = $this->financialAggregate(
            (clone $allOrders)->operationalStatus('late')
        );
        $summary['deleted'] = $this->financialAggregate(
            (clone $allOrders)->onlyTrashed()
        );

        return $summary;
    }

    /**
     * Build a count-only summary for operational staff. Avoid selecting or
     * aggregating price/fee columns altogether, so an accidental serializer
     * change cannot expose platform revenue to an orders-view profile.
     *
     * @param  Builder<Order>  $allOrders
     * @return array<string, array{count: int}>
     */
    private function operationalSummaryFor(Builder $allOrders): array
    {
        $summary = [
            'all' => ['count' => (clone $allOrders)->count()],
        ];

        foreach (Order::STATUSES as $status) {
            $summary[$status] = ['count' => 0];
        }

        $byStatus = (clone $allOrders)
            ->selectRaw('status, COUNT(*) AS summary_count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        foreach (Order::STATUSES as $status) {
            $summary[$status] = [
                'count' => (int) ($byStatus->get($status)?->summary_count ?? 0),
            ];
        }

        $summary['late'] = [
            'count' => (clone $allOrders)->operationalStatus('late')->count(),
        ];
        $summary['deleted'] = [
            'count' => (clone $allOrders)->onlyTrashed()->count(),
        ];

        return $summary;
    }

    /**
     * Preserve the original lightweight count contract for existing clients
     * while the dashboard upgrades to the richer `summary` payload.
     *
     * @param  array<string, array{count: int, amount?: int, order_value?: int, delivery_fee?: int}>  $summary
     * @return array<string, int>
     */
    private function countsFromSummary(array $summary): array
    {
        return collect($summary)
            ->mapWithKeys(fn (array $item, string $key) => [$key => $item['count']])
            ->all();
    }

    /**
     * @param  Builder<Order>  $query
     * @return array{count: int, amount: int, order_value: int, delivery_fee: int}
     */
    private function financialAggregate(Builder $query): array
    {
        return $this->financialAggregatePayload(
            (clone $query)->selectRaw(<<<'SQL'
                COUNT(*) AS summary_count,
                COALESCE(SUM(COALESCE(price, 0)), 0) AS summary_order_value,
                COALESCE(SUM(COALESCE(fee, 0)), 0) AS summary_delivery_fee,
                COALESCE(SUM(COALESCE(price, 0) + COALESCE(fee, 0)), 0) AS summary_amount
            SQL)->first()
        );
    }

    /**
     * @return array{count: int, amount: int, order_value: int, delivery_fee: int}
     */
    private function emptyFinancialAggregate(): array
    {
        return [
            'count' => 0,
            'amount' => 0,
            'order_value' => 0,
            'delivery_fee' => 0,
        ];
    }

    /**
     * @param  object|null  $aggregate
     * @return array{count: int, amount: int, order_value: int, delivery_fee: int}
     */
    private function financialAggregatePayload(?object $aggregate): array
    {
        if (! $aggregate) {
            return $this->emptyFinancialAggregate();
        }

        return [
            'count' => (int) ($aggregate->summary_count ?? 0),
            'amount' => (int) ($aggregate->summary_amount ?? 0),
            'order_value' => (int) ($aggregate->summary_order_value ?? 0),
            'delivery_fee' => (int) ($aggregate->summary_delivery_fee ?? 0),
        ];
    }

    /**
     * Load management directories only when an administrator uses a filter or
     * opens an assignment dialog. They can be very large on a live platform.
     *
     * @return array<string, mixed>
     */
    private function directoryPayload(
        string $directory,
        int $assignmentFor,
        bool $includeCouriers,
        bool $includeBranches,
        BranchDashboardScope $scope,
        ?int $selectedBranchId,
        DashboardBranchFilter $branchFilter,
    ): array
    {
        if ($directory === 'courier_filters') {
            return [
                'courierFilters' => $this->filteredUsers(User::query(), $scope, $selectedBranchId, $branchFilter)
                    ->whereIn('role', User::DIRECT_ORDER_COURIER_ROLES)
                    ->where('courier_verified', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'phone', 'role', 'status'])
                    ->map(fn (User $courier) => [
                        'id' => $courier->id,
                        'name' => $courier->name,
                        'phone' => $courier->phone,
                        'role' => $courier->role,
                        'status' => $courier->status,
                    ])->values(),
            ];
        }

        $order = $this->filteredOrders($scope, $selectedBranchId, $branchFilter)
            ->select(['id', 'tenant_id', 'province_id', 'branch_id', 'workflow_stage'])
            ->findOrFail($assignmentFor);
        $this->assertBranchMayOperateOrder($order, $scope);

        $couriers = collect();
        if ($includeCouriers && $order->province_id) {
            $couriers = $this->filteredUsers(User::query(), $scope, $selectedBranchId, $branchFilter)
                ->whereIn('role', User::DIRECT_ORDER_COURIER_ROLES)
                ->where('status', 'active')
                ->where('courier_verified', true)
                ->whereHas('provinces', fn ($provinces) => $provinces->whereKey($order->province_id))
                ->with('provinces:id,name_ar')
                ->get(['id', 'name', 'phone', 'role'])
                ->map(fn (User $courier) => [
                    'id' => $courier->id,
                    'name' => $courier->name,
                    'phone' => $courier->phone,
                    'role' => $courier->role,
                    'assignment_roles' => app(OrderOperationalAssignmentService::class)->modesFor($courier),
                    'provinces' => $courier->provinces->map(fn ($province) => [
                        'id' => $province->id,
                        'name_ar' => $province->name_ar,
                    ])->values(),
                ])->values();
        }

        $branches = $includeBranches
            ? Branch::withoutGlobalScope(TenantScope::class)
                ->where('is_active', true)
                ->where(function ($branchQuery) use ($order): void {
                    $branchQuery
                        ->where('is_platform_managed', true)
                        ->orWhere('tenant_id', $order->tenant_id);
                })
                ->orderBy('name_ar')
                ->get(['id', 'tenant_id', 'code', 'name_ar', 'city', 'is_platform_managed'])
                ->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'tenant_id' => $branch->tenant_id,
                    'code' => $branch->code,
                    'name' => $branch->name_ar,
                    'city' => $branch->city,
                    'is_platform_managed' => $branch->is_platform_managed,
                ])->values()
            : collect();

        return compact('couriers', 'branches');
    }

    public function status(Request $request, Order $order)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $order = $this->mutableScopedOrder($order, $scope);
        $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        app(OrderWorkflowService::class)->changeStatus($order, $request->input('status'), $request->user(), $request->input('note'));

        return back()->with('success', __('orders.status_changed'));
    }

    /**
     * Correct customer-facing order data before a courier accepts the job.
     * Operational and financial history is left immutable after assignment.
     */
    public function update(Request $request, Order $order)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $order = $this->mutableScopedOrder($order, $scope);
        abort_unless($order->status === 'pending' && ! $order->courier_id, 422, 'يمكن تعديل الطلبات قيد الانتظار وغير المعيّنة فقط.');

        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            'phone' => ['bail', 'required', 'string', new IraqiMobilePhone],
            'phone2' => ['bail', 'nullable', 'string', new IraqiMobilePhone],
            'address_ar' => ['required', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'delivery_vehicle' => ['required', Rule::in(['normal', 'bike', 'sedan', 'suv', 'truck'])],
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:1', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $before = $order->only(array_keys($data));
        $order->update([
            ...$data,
            'customer_name_en' => $request->input('customer_name_en') ?: $data['customer_name_ar'],
            'address_en' => $request->input('address_en') ?: $data['address_ar'],
        ]);

        ActivityLog::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'order.updated_by_admin',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'data' => ['track_no' => $order->track_no, 'before' => $before, 'after' => $order->only(array_keys($data))],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'تم تعديل الطلب '.$order->track_no.'.');
    }

    /** Soft-delete only an untouched order; an administrator can restore it. */
    public function destroy(Request $request, Order $order)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $order = $this->mutableScopedOrder($order, $scope);
        $trackNo = DB::transaction(function () use ($request, $order, $scope): string {
            $locked = $this->scopedOrders($scope)
                ->lockForUpdate()
                ->findOrFail($order->id);
            $this->assertBranchMayOperateOrder($locked, $scope);

            abort_unless(
                $locked->status === 'pending'
                    && ! $locked->courier_id
                    && ! $locked->pickup_courier_id
                    && ! $locked->delivery_courier_id,
                422,
                'يمكن حذف الطلبات قيد الانتظار وغير المعيّنة فقط.'
            );

            abort_if(
                Transaction::withoutGlobalScopes()->where('order_id', $locked->id)->exists(),
                422,
                'لا يمكن حذف طلب مرتبط بحركة مالية. غيّر حالته أو راجع السجل المالي.'
            );

            $locked->delete();

            ActivityLog::create([
                'tenant_id' => $locked->tenant_id,
                'user_id' => $request->user()->id,
                'action' => 'order.soft_deleted_by_admin',
                'subject_type' => 'order',
                'subject_id' => $locked->id,
                'data' => ['track_no' => $locked->track_no, 'status' => $locked->status],
                'ip' => $request->ip(),
            ]);

            return $locked->track_no;
        });

        return back()->with('success', 'تم حذف الطلب '.$trackNo.'. ويمكن استعادته من تبويب الطلبات المحذوفة.');
    }

    public function assignCourier(Request $request, Order $order)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $order = $this->mutableScopedOrder($order, $scope);
        $request->validate([
            'courier_id' => ['required', Rule::exists('users', 'id')->where(function ($couriers) use ($scope) {
                $couriers
                    ->whereIn('role', User::DIRECT_ORDER_COURIER_ROLES)
                    ->where('status', 'active')
                    ->where('courier_verified', true)
                    ->whereNull('deleted_at');
                if ($scope->hasBranchScope()) {
                    $couriers->where('branch_id', $scope->branchId());
                }
            })],
            'assignment_role' => ['nullable', Rule::in(OrderOperationalAssignmentService::ASSIGNMENT_ROLES)],
        ]);

        $courier = $this->scopedUsers(User::query(), $scope)
            ->whereKey($request->integer('courier_id'))
            ->whereIn('role', User::DIRECT_ORDER_COURIER_ROLES)
            ->where('status', 'active')
            ->where('courier_verified', true)
            ->firstOrFail();

        app(OrderOperationalAssignmentService::class)->assign(
            $order,
            $courier,
            $request->user(),
            $request->input('assignment_role'),
            'تم تعيين المندوب من لوحة الإدارة.',
        );

        return back()->with('success', __('orders.courier_assigned'));
    }

    /**
     * An overdue pickup is recoverable without inventing a sixth delivery
     * status. The service returns the reserved courier budget and offers the
     * same order again, leaving the operation in the normal audit trail.
     */
    public function reofferOverduePickup(Request $request, Order $order)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $order = $this->mutableScopedOrder($order, $scope);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        app(OrderPickupRecoveryService::class)->reoffer(
            $order,
            $request->user(),
            $data['note'] ?? null,
        );

        return back()->with('success', 'تمت إعادة طرح الطلب، وأُعيدت الميزانية المحجوزة للمندوب السابق.');
    }

    public function assignBranches(Request $request, Order $order)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        abort_unless(! $scope->hasBranchScope(), 403);
        $order = $this->scopedOrder($order, $scope);
        $data = $request->validate([
            'origin_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'destination_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchIds = collect([$data['origin_branch_id'] ?? null, $data['destination_branch_id'] ?? null])
            ->filter()
            ->unique()
            ->values();

        abort_unless($branchIds->isNotEmpty(), 422, 'اختر فرعاً واحداً على الأقل.');

        $branches = Branch::withoutGlobalScope(TenantScope::class)
            ->availableForTenant((int) $order->tenant_id)
            ->whereIn('id', $branchIds)
            ->get();
        abort_unless(
            $branches->count() === $branchIds->count()
                && $branches->every(fn (Branch $branch) => $branch->canServeTenant((int) $order->tenant_id)),
            422,
            'يجب أن تكون الفروع نشطة وتتبع شبكة الإدارة أو حساب التاجر نفسه.'
        );

        $originId = $data['origin_branch_id'] ?? null;
        $destinationId = $data['destination_branch_id'] ?? null;
        $stage = $originId && $destinationId && $originId !== $destinationId
            ? 'awaiting_transfer'
            : 'at_origin_branch';

        $order->update([
            'origin_branch_id' => $originId,
            'destination_branch_id' => $destinationId,
            'branch_id' => $destinationId ?: $originId,
            'workflow_stage' => $stage,
        ]);

        OrderMovement::withoutGlobalScopes()->create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'from_branch_id' => $originId,
            'to_branch_id' => $destinationId,
            'actor_id' => $request->user()->id,
            'stage' => $stage,
            'note' => 'تم تحديد مسار الفروع من لوحة الإدارة.',
            'meta' => ['origin_branch_id' => $originId, 'destination_branch_id' => $destinationId],
            'occurred_at' => now(),
        ]);

        return back()->with('success', 'تم حفظ مسار الفروع للطلب.');
    }

    /**
     * Merchant deletion is deliberately a soft delete. Give an administrator
     * a safe recovery path instead of ever recreating an order by hand or
     * losing its activity, chat, and ledger references.
     */
    public function restore(Request $request, int $orderId)
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $trackNo = DB::transaction(function () use ($request, $orderId, $scope): string {
            // Use an explicit id instead of the normal route binder, because
            // the global binder intentionally excludes soft-deleted orders.
            $order = $this->scopedOrders($scope)
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($orderId);
            $this->assertBranchMayOperateOrder($order, $scope);

            abort_unless($order->trashed(), 422, 'هذا الطلب ليس ضمن الطلبات المحذوفة.');

            $order->restore();

            ActivityLog::create([
                'tenant_id' => $order->tenant_id,
                'user_id' => $request->user()->id,
                'action' => 'order.restored_by_admin',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'data' => ['track_no' => $order->track_no, 'status' => $order->status],
                'ip' => $request->ip(),
            ]);

            return $order->track_no;
        });

        return back()->with('success', 'تمت استعادة الطلب '.$trackNo.'.');
    }

    /**
     * Keep the dashboard's list and detail sheet driven by one operational
     * payload. Monetary detail is appended only for the exact financial read
     * capability (or the explicit order-editor flow).
     */
    private function orderPayload(Order $order, $movementBranches, bool $includeFinancial, BranchDashboardScope $scope): array
    {
        $merchant = $this->visibleUser($order->merchant, $scope)
            ? [
                'id' => $order->merchant->id,
                'name' => $order->merchant->name,
                'shop_name' => $order->merchant->shop_name,
                'phone' => $order->merchant->phone,
                'address' => $order->merchant->address,
            ]
            : (! $scope->hasBranchScope() && $order->tenant ? [
                'id' => null,
                'name' => $order->tenant->name,
                'shop_name' => null,
                'phone' => null,
                'address' => null,
            ] : null);

        $payload = [
            'id' => $order->id,
            'track_no' => $order->track_no,
            'source' => $order->source,
            'status' => $order->status,
            'workflow_stage' => $order->workflow_stage,
            'can_operate' => $this->canOperateOrder($order, $scope),
            'tenant_id' => $merchant || ! $scope->hasBranchScope() ? $order->tenant_id : null,
            'tenant' => $merchant ? $order->tenant?->name : null,
            'customer' => [
                'name' => $order->customer_name_ar,
                'name_en' => $order->customer_name_en,
                'phone' => $order->phone,
                'phone2' => $order->phone2,
                'address' => $order->address_ar,
                'address_en' => $order->address_en,
            ],
            // Keep the flat keys while older dashboard views are still being
            // migrated, and expose structured data for the detail sheet.
            'customer_name_ar' => $order->customer_name_ar,
            'phone' => $order->phone,
            'address_ar' => $order->address_ar,
            'pickup_latitude' => $order->pickup_latitude === null ? null : (float) $order->pickup_latitude,
            'pickup_longitude' => $order->pickup_longitude === null ? null : (float) $order->pickup_longitude,
            'pickup_location_label' => $order->pickup_location_label,
            'return_reason' => $order->return_reason,
            'order_type' => $order->order_type,
            'delivery_vehicle' => $order->delivery_vehicle,
            'vehicle_note' => $order->vehicle_note,
            'notes' => $order->notes,
            'date' => $order->date?->toDateString(),
            'created_at' => $this->iso($order->created_at),
            'updated_at' => $this->iso($order->updated_at),
            'deleted_at' => $this->iso($order->deleted_at),
            'accepted_at' => $this->iso($order->accepted_at),
            'picked_at' => $this->iso($order->picked_at),
            'delivered_at' => $this->iso($order->delivered_at),
            'returned_at' => $this->iso($order->returned_at),
            'returned_to_merchant_at' => $this->iso($order->returned_to_merchant_at),
            'pickup_deadline_at' => $this->iso($order->pickup_deadline_at),
            'province_id' => $order->province_id,
            'province' => $order->province ? [
                'id' => $order->province->id,
                'name_ar' => $order->province->name_ar,
                'name_en' => $order->province->name_en,
                'name_ku' => $order->province->name_ku,
            ] : null,
            'merchant' => $merchant,
            'origin_branch_id' => $this->visibleBranchId($order->origin_branch_id, $scope),
            'destination_branch_id' => $this->visibleBranchId($order->destination_branch_id, $scope),
            'origin_branch' => $this->branchPayload($order->originBranch, $scope),
            'destination_branch' => $this->branchPayload($order->destinationBranch, $scope),
            'courier' => $this->personPayload($this->operationalCourier($order), $scope),
            'timeline' => $this->timelinePayload($order, $movementBranches, $merchant, $scope),
        ];

        if ($includeFinancial) {
            $fee = $order->fee === null ? null : (int) $order->fee;
            $payload['price'] = (int) $order->price;
            $payload['fee'] = $fee;
            // The pricing quote is immutable; the applied amount is written
            // only by the courier's two-step return workflow.
            $payload['return_fee'] = (int) ($order->return_fee ?? 0);
            $payload['return_fee_applied'] = (int) ($order->return_fee_applied ?? 0);
            $payload['return_fee_mode'] = $order->return_fee_mode;
            $payload['return_fee_charged_at'] = $this->iso($order->return_fee_charged_at);
            $payload['financial'] = [
                'order_value' => (int) $order->price,
                'delivery_fee' => $fee,
                'net_to_merchant' => max(0, (int) $order->price - ($fee ?? 0)),
                'return_fee_quote' => (int) ($order->return_fee ?? 0),
                'return_fee_applied' => (int) ($order->return_fee_applied ?? 0),
                'returned_to_merchant_at' => $this->iso($order->returned_to_merchant_at),
                'return_fee_charged_at' => $this->iso($order->return_fee_charged_at),
            ];
        }

        return $payload;
    }

    private function timelinePayload(Order $order, $movementBranches, ?array $merchant, BranchDashboardScope $scope): array
    {
        $events = collect();
        $hasCreationLog = false;

        foreach ($order->statusLogs as $log) {
            $isCreated = $log->from_status === null;
            $hasCreationLog = $hasCreationLog || $isCreated;

            $events->push([
                'kind' => $isCreated ? 'created' : 'status',
                'status' => $log->to_status,
                'from_status' => $log->from_status,
                'stage' => null,
                'note' => $log->note,
                'actor' => $this->personPayload($log->user, $scope),
                'from_branch' => null,
                'to_branch' => null,
                'at' => $this->iso($log->created_at),
            ]);
        }

        if (! $hasCreationLog && $order->created_at) {
            $events->push([
                'kind' => 'created',
                'status' => $order->status,
                'from_status' => null,
                'stage' => 'created',
                'note' => null,
                'actor' => $merchant,
                'from_branch' => null,
                'to_branch' => null,
                'at' => $this->iso($order->created_at),
            ]);
        }

        foreach ($order->movements as $movement) {
            $events->push([
                'kind' => data_get($movement->meta, 'event') === 'courier_assignment' ? 'assignment' : 'movement',
                'status' => null,
                'from_status' => null,
                'stage' => $movement->stage,
                'note' => $movement->note,
                'actor' => $this->personPayload($movement->actor, $scope),
                'assignment_role' => data_get($movement->meta, 'assignment_role'),
                // Historic movement metadata can include a courier name
                // without a branch id. Never serialise that unscoped
                // snapshot to another branch.
                'assignee' => ! $scope->hasBranchScope() && data_get($movement->meta, 'event') === 'courier_assignment' ? [
                    'id' => data_get($movement->meta, 'assignee_id'),
                    'name' => data_get($movement->meta, 'assignee_name'),
                    'role' => data_get($movement->meta, 'assignee_role'),
                ] : null,
                'from_branch' => $this->branchPayload($movementBranches->get($movement->from_branch_id), $scope),
                'to_branch' => $this->branchPayload($movementBranches->get($movement->to_branch_id), $scope),
                'at' => $this->iso($movement->occurred_at),
            ]);
        }

        return $events
            ->filter(fn (array $event) => filled($event['at']))
            ->sortByDesc('at')
            ->values()
            ->all();
    }

    private function personPayload(?User $user, ?BranchDashboardScope $scope = null): ?array
    {
        return $this->visibleUser($user, $scope) ? [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'vehicle' => $user->vehicle,
            'role' => $user->role,
        ] : null;
    }

    /**
     * Read historic split assignments safely without making them operational
     * again. New orders always populate courier_id, which is authoritative.
     */
    private function operationalCourier(Order $order): ?User
    {
        return $order->courier ?: $order->deliveryCourier ?: $order->pickupCourier;
    }

    private function branchPayload(?Branch $branch, ?BranchDashboardScope $scope = null): ?array
    {
        return $branch && (! $scope?->hasBranchScope() || $scope->allowsBranch($branch)) ? [
            'id' => $branch->id,
            'name' => $branch->name_ar,
            'name_en' => $branch->name_en,
            'name_ku' => $branch->name_ku,
            'city' => $branch->city,
        ] : null;
    }

    private function visibleBranchId(?int $branchId, BranchDashboardScope $scope): ?int
    {
        if ($branchId === null) {
            return null;
        }

        return ! $scope->hasBranchScope() || $scope->allowsBranch($branchId)
            ? $branchId
            : null;
    }

    /** @return Builder<Order> */
    private function scopedOrders(BranchDashboardScope $scope): Builder
    {
        $query = Order::withoutGlobalScope(TenantScope::class);

        return $scope->hasBranchScope() ? $scope->restrictOrders($query) : $query;
    }

    /** @return Builder<User> */
    private function scopedUsers(Builder $query, BranchDashboardScope $scope): Builder
    {
        return $scope->hasBranchScope() ? $scope->restrictUsers($query) : $query;
    }

    /** @return Builder<Order> */
    private function filteredOrders(
        BranchDashboardScope $scope,
        ?int $selectedBranchId,
        DashboardBranchFilter $branchFilter,
    ): Builder {
        $query = Order::withoutGlobalScope(TenantScope::class);

        // The scope always wins for a branch account. This deliberately
        // ignores a forged branch_id rather than turning it into another
        // route endpoint the account can inspect.
        if ($scope->hasBranchScope()) {
            return $scope->restrictOrders($query);
        }

        return $branchFilter->restrictOrders($query, $selectedBranchId);
    }

    /** @return Builder<User> */
    private function filteredUsers(
        Builder $query,
        BranchDashboardScope $scope,
        ?int $selectedBranchId,
        DashboardBranchFilter $branchFilter,
    ): Builder {
        if ($scope->hasBranchScope()) {
            return $scope->restrictUsers($query);
        }

        return $branchFilter->restrictByColumn(
            $query,
            $selectedBranchId,
            $query->getModel()->qualifyColumn('branch_id'),
        );
    }

    private function scopedOrder(Order $boundOrder, BranchDashboardScope $scope): Order
    {
        return $this->scopedOrders($scope)
            ->whereKey($boundOrder->id)
            ->firstOrFail();
    }

    /**
     * A route endpoint grants the branch a read scope so it can follow an
     * incoming or outgoing transfer. That must never make either endpoint a
     * generic editor while custody is elsewhere or the parcel is in transit.
     */
    private function mutableScopedOrder(Order $boundOrder, BranchDashboardScope $scope): Order
    {
        $order = $this->scopedOrder($boundOrder, $scope);
        $this->assertBranchMayOperateOrder($order, $scope);

        return $order;
    }

    private function assertBranchMayOperateOrder(Order $order, BranchDashboardScope $scope): void
    {
        abort_unless(
            $this->canOperateOrder($order, $scope),
            403,
            'لا يمكن للفرع تعديل طلب خارج عهدته التشغيلية أو أثناء النقل بين الفروع.'
        );
    }

    private function canOperateOrder(Order $order, BranchDashboardScope $scope): bool
    {
        if (! $scope->hasBranchScope()) {
            return true;
        }

        // Transfer actions have their own origin/destination checks in the
        // transfer controller. Generic order actions are frozen until the
        // destination confirms receipt, which prevents either endpoint from
        // rewriting a shared order in transit.
        if (in_array($order->workflow_stage, ['awaiting_transfer', 'in_transfer'], true)) {
            return false;
        }

        return (int) $order->branch_id === (int) $scope->branchId();
    }

    private function visibleUser(?User $user, ?BranchDashboardScope $scope = null): bool
    {
        return $user !== null
            && (! $scope?->hasBranchScope() || (int) $user->branch_id === (int) $scope->branchId());
    }

    private function iso($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return filled($value)
            ? Carbon::parse($value)->toIso8601String()
            : null;
    }
}
