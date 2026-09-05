<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\BranchTransferService;
use App\Services\DashboardBranchFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminBranchTransferController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $scope = $this->branchScope($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
        $canCreateTransfers = $user->canUseAdminPermission('transfers', 'create');
        $canDispatchTransfers = $user->canUseAdminPermission('transfers', 'dispatch');
        $canReceiveTransfers = $user->canUseAdminPermission('transfers', 'receive');
        // A transfer manifest is an operational record. Its COD/order value
        // is financial data, so dispatch/receive/create access alone must
        // not reveal it. Reuse the precise balance-directory capability
        // instead of broadening the transfers module with an implicit grant.
        $canViewTransferFinancials = $user->canUseAdminPermission('finance', 'view_balances');

        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_merge(['all'], BranchTransfer::STATUSES))],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = $this->visibleTransfers($scope, $selectedBranchId)
            ->with([
                'originBranch:id,tenant_id,code,name_ar,name_en,name_ku,city',
                'destinationBranch:id,tenant_id,code,name_ar,name_en,name_ku,city',
                // A branch account never needs a transporter's direct phone
                // number to operate a manifest.  In particular, an inbound
                // manifest originates outside its local boundary.  Do not
                // even select the contact field for scoped dashboards.
                'transporter' => fn ($transporter) => $transporter->select(
                    $scope->hasBranchScope()
                        ? ['id', 'name']
                        : ['id', 'name', 'phone'],
                ),
                'orders' => fn ($orders) => $orders
                    ->select([
                        'orders.id',
                        'orders.tenant_id',
                        'orders.merchant_id',
                        'orders.track_no',
                        'orders.customer_name_ar',
                        'orders.status',
                        'orders.workflow_stage',
                    ])
                    ->when($canViewTransferFinancials, fn ($orders) => $orders->addSelect('orders.price'))
                    ->with([
                        'merchant:id,name,shop_name',
                        'tenant:id,name',
                    ]),
            ]);

        if (($data['status'] ?? 'all') !== 'all') {
            $query->where('status', $data['status']);
        }

        if ($q = $data['q'] ?? null) {
            $query->where(function ($transfers) use ($q): void {
                $transfers->where('reference', 'like', "%{$q}%")
                    ->orWhereHas('orders', fn ($orders) => $orders->withoutGlobalScope(TenantScope::class)
                        ->where('track_no', 'like', "%{$q}%"));
            });
        }

        $transfers = $query->latest('id')->paginate(25)->withQueryString();
        $transfers->through(fn (BranchTransfer $transfer) => $this->transferPayload($transfer, $canViewTransferFinancials, $scope));

        $props = [
            'transfers' => $transfers,
            'filter' => $data['status'] ?? 'all',
            'q' => $data['q'] ?? '',
            'canCreateTransfers' => $canCreateTransfers,
            'canDispatchTransfers' => $canDispatchTransfers,
            'canReceiveTransfers' => $canReceiveTransfers,
            'canViewTransferFinancials' => $canViewTransferFinancials,
            'branchFilter' => $branchFilter->payload($request, $scope),
            'counts' => [
                'all' => $this->visibleTransfers($scope, $selectedBranchId)->count(),
                BranchTransfer::DRAFT => $this->visibleTransfers($scope, $selectedBranchId)->where('status', BranchTransfer::DRAFT)->count(),
                BranchTransfer::DISPATCHED => $this->visibleTransfers($scope, $selectedBranchId)->where('status', BranchTransfer::DISPATCHED)->count(),
                BranchTransfer::RECEIVED => $this->visibleTransfers($scope, $selectedBranchId)->where('status', BranchTransfer::RECEIVED)->count(),
            ],
        ];

        // Branch choices, transporter contact details, and eligible-order
        // customer data are needed exclusively to create a manifest. Do not
        // send them to an operator who may only review, dispatch, or receive
        // existing transfers.
        if ($canCreateTransfers) {
            if ($scope->hasBranchScope()) {
                // A local manager may choose a live network destination, but
                // receives only the identifier and display name for foreign
                // branches. Their own origin is enforced on write below.
                $props['branches'] = Branch::withoutGlobalScope(TenantScope::class)
                    ->where('is_active', true)
                    ->where('is_platform_managed', true)
                    ->orderBy('name_ar')
                    ->get(['id', 'name_ar'])
                    ->map(fn (Branch $branch) => ['id' => $branch->id, 'name_ar' => $branch->name_ar]);
                $props['transporters'] = User::withoutGlobalScopes()
                    ->where('role', 'transporter')
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $transporter) => ['id' => $transporter->id, 'name' => $transporter->name]);
            } else {
                $props['branches'] = Branch::withoutGlobalScope(TenantScope::class)
                    ->where('is_active', true)
                    ->orderBy('city')
                    ->orderBy('name_ar')
                    ->get(['id', 'tenant_id', 'is_platform_managed', 'code', 'name_ar', 'name_en', 'name_ku', 'city'])
                    ->map(fn (Branch $branch) => $this->branchPayload($branch) + [
                        'tenant_id' => $branch->tenant_id,
                        'is_platform_managed' => (bool) $branch->is_platform_managed,
                    ]);
                $props['transporters'] = User::withoutGlobalScopes()
                    ->where('role', 'transporter')
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(['id', 'name', 'phone'])
                    ->map(fn (User $transporter) => ['id' => $transporter->id, 'name' => $transporter->name, 'phone' => $transporter->phone]);
            }

            $props['eligible_orders'] = $this->eligibleOrders($canViewTransferFinancials, $scope, $selectedBranchId);
        }

        return Inertia::render('Admin/Transfers', $props);
    }

    public function store(Request $request, BranchTransferService $transfers)
    {
        $scope = $this->branchScope($request);
        $data = $request->validate([
            'origin_branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'destination_branch_id' => ['required', 'integer', 'different:origin_branch_id', Rule::exists('branches', 'id')],
            'transporter_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'integer', 'distinct', Rule::exists('orders', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($scope->hasBranchScope()) {
            // The origin is never a client-selected branch for a local
            // manager. The destination remains a controlled network choice.
            abort_unless($scope->allowsBranch((int) $data['origin_branch_id']), 403);

            $destination = Branch::withoutGlobalScope(TenantScope::class)
                ->whereKey($data['destination_branch_id'])
                ->where('tenant_id', Tenant::platform()->id)
                ->where('is_platform_managed', true)
                ->where('is_active', true)
                ->firstOrFail();

            $orders = Order::withoutGlobalScope(TenantScope::class)
                ->whereIn('id', $data['order_ids'])
                ->where('origin_branch_id', $scope->branchId())
                ->where('destination_branch_id', $destination->id)
                ->get(['id']);

            // The transfer service repeats workflow/state checks under its
            // transaction. This controller check is the branch boundary for
            // every browser-supplied order id before that global service runs.
            abort_unless($orders->count() === count($data['order_ids']), 403);

            User::withoutGlobalScopes()
                ->whereKey($data['transporter_id'])
                ->where('role', 'transporter')
                ->where('status', 'active')
                ->firstOrFail();
        }

        $transfers->create($request->user(), $data);

        return back()->with('success', __('Transfer created successfully.'));
    }

    public function dispatch(Request $request, int $transfer, BranchTransferService $transfers)
    {
        $scope = $this->branchScope($request);
        $transfer = $this->actionTransfer($transfer, $scope, 'origin_branch_id');

        $transfers->dispatch($transfer->id, $request->user());

        return back()->with('success', __('Transfer dispatched successfully.'));
    }

    public function receive(Request $request, int $transfer, BranchTransferService $transfers)
    {
        $scope = $this->branchScope($request);
        $transfer = $this->actionTransfer($transfer, $scope, 'destination_branch_id');

        $transfers->receive($transfer->id, $request->user());

        return back()->with('success', __('Transfer received successfully.'));
    }

    /** @return array<int, array<string, mixed>> */
    private function eligibleOrders(bool $includeFinancialValues, BranchDashboardScope $scope, ?int $selectedBranchId = null): array
    {
        $activeTransferOrderIds = DB::table('branch_transfer_orders as transfer_orders')
            ->join('branch_transfers as transfers', 'transfers.id', '=', 'transfer_orders.branch_transfer_id')
            ->whereIn('transfers.status', [BranchTransfer::DRAFT, BranchTransfer::DISPATCHED])
            ->pluck('transfer_orders.order_id');

        $orders = Order::withoutGlobalScope(TenantScope::class)
            ->select([
                'orders.id',
                'orders.tenant_id',
                'orders.merchant_id',
                'orders.track_no',
                'orders.customer_name_ar',
                'orders.status',
                'orders.workflow_stage',
                'orders.origin_branch_id',
                'orders.destination_branch_id',
            ])
            ->when($includeFinancialValues, fn ($orders) => $orders->addSelect('orders.price'))
            ->with([
                'merchant:id,name,shop_name',
                'tenant:id,name',
                'originBranch:id,name_ar,name_en,name_ku,city',
                'destinationBranch:id,name_ar,name_en,name_ku,city',
            ])
            ->where('workflow_stage', 'awaiting_transfer')
            ->whereNotIn('status', ['delivered', 'returned', 'cancelled', 'damaged'])
            ->whereNotNull('origin_branch_id')
            ->whereNotNull('destination_branch_id')
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->when($activeTransferOrderIds->isNotEmpty(), fn ($orders) => $orders->whereNotIn('id', $activeTransferOrderIds))
            ->latest('id')
            ->limit(250);

        if ($scope->hasBranchScope()) {
            // Creation is intentionally outward only. Incoming orders are
            // visible through their existing transfer manifests, not reusable
            // as a new origin manifest by the destination manager.
            $orders->where('orders.origin_branch_id', $scope->branchId());
        } elseif ($selectedBranchId) {
            app(DashboardBranchFilter::class)->restrictOrders($orders, $selectedBranchId);
        }

        return $orders
            ->get()
            ->map(function (Order $order) use ($includeFinancialValues, $scope): array {
                $payload = [
                    'id' => $order->id,
                    'tenant_id' => $order->tenant_id,
                    'tenant' => $order->tenant?->name,
                    'track_no' => $order->track_no,
                    'customer' => $order->customer_name_ar,
                    'merchant' => $order->merchant?->shop_name ?: $order->merchant?->name,
                    'status' => $order->status,
                    'workflow_stage' => $order->workflow_stage,
                    'origin_branch_id' => $order->origin_branch_id,
                    'destination_branch_id' => $order->destination_branch_id,
                    'origin_branch' => $this->branchPayload($order->originBranch, $scope),
                    'destination_branch' => $this->branchPayload($order->destinationBranch, $scope),
                ];

                if ($includeFinancialValues) {
                    $payload['price'] = (int) $order->price;
                }

                return $payload;
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function transferPayload(BranchTransfer $transfer, bool $includeFinancialValues, BranchDashboardScope $scope): array
    {
        // Destination managers must be able to reconcile the physical
        // handoff, but they do not need the originating merchant, tenant, or
        // customer details to do that.  The remote branch's compact payload,
        // tracking number, and lifecycle state are the operational minimum.
        $isIncomingForScopedBranch = $scope->hasBranchScope()
            && (int) $transfer->destination_branch_id === (int) $scope->branchId();

        return [
            'id' => $transfer->id,
            'reference' => $transfer->reference,
            'status' => $transfer->status,
            ...(! $isIncomingForScopedBranch ? ['notes' => $transfer->notes] : []),
            'created_at' => $transfer->created_at?->toIso8601String(),
            'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            'received_at' => $transfer->received_at?->toIso8601String(),
            'origin_branch' => $this->branchPayload($transfer->originBranch, $scope),
            'destination_branch' => $this->branchPayload($transfer->destinationBranch, $scope),
            'transporter' => $transfer->transporter ? [
                'id' => $transfer->transporter->id,
                'name' => $transfer->transporter->name,
                ...(! $scope->hasBranchScope() ? ['phone' => $transfer->transporter->phone] : []),
            ] : null,
            'orders' => $transfer->orders->map(function (Order $order) use ($includeFinancialValues, $isIncomingForScopedBranch): array {
                $payload = [
                    'id' => $order->id,
                    'track_no' => $order->track_no,
                    'status' => $order->status,
                    'workflow_stage' => $order->workflow_stage,
                ];

                if (! $isIncomingForScopedBranch) {
                    $payload += [
                        'customer' => $order->customer_name_ar,
                        'merchant' => $order->merchant?->shop_name ?: $order->merchant?->name ?: $order->tenant?->name,
                        'tenant' => $order->tenant?->name,
                    ];
                }

                if ($includeFinancialValues && ! $isIncomingForScopedBranch) {
                    $payload['price'] = (int) $order->price;
                }

                return $payload;
            })->values()->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function branchPayload(?Branch $branch, ?BranchDashboardScope $scope = null): ?array
    {
        if ($branch && $scope?->hasBranchScope() && ! $scope->allowsBranch($branch)) {
            return [
                'id' => $branch->id,
                'name_ar' => $branch->name_ar,
            ];
        }

        return $branch ? [
            'id' => $branch->id,
            'code' => $branch->code,
            'name_ar' => $branch->name_ar,
            'name_en' => $branch->name_en,
            'name_ku' => $branch->name_ku,
            'city' => $branch->city,
        ] : null;
    }

    /** @return Builder<BranchTransfer> */
    private function visibleTransfers(BranchDashboardScope $scope, ?int $selectedBranchId = null): Builder
    {
        $transfers = BranchTransfer::withoutGlobalScope(TenantScope::class);

        $branchId = $scope->hasBranchScope() ? $scope->branchId() : $selectedBranchId;

        if (! $branchId) {
            return $transfers;
        }

        return $transfers->where(function (Builder $visible) use ($branchId): void {
            $visible
                ->where('origin_branch_id', $branchId)
                ->orWhere('destination_branch_id', $branchId);
        });
    }

    private function actionTransfer(int $transferId, BranchDashboardScope $scope, string $branchColumn): BranchTransfer
    {
        $transfers = BranchTransfer::withoutGlobalScope(TenantScope::class)
            ->whereKey($transferId);

        if ($scope->hasBranchScope()) {
            $transfers->where($branchColumn, $scope->branchId());
        }

        return $transfers->firstOrFail();
    }

    private function branchScope(Request $request): BranchDashboardScope
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);

        if ($scope->requiresBranchScope() && ! $scope->isAvailable()) {
            abort(403);
        }

        return $scope;
    }
}
