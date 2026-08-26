<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\BranchTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminBranchTransferController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_merge(['all'], BranchTransfer::STATUSES))],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = BranchTransfer::withoutGlobalScope(TenantScope::class)
            ->with([
                'originBranch:id,tenant_id,code,name_ar,name_en,name_ku,city',
                'destinationBranch:id,tenant_id,code,name_ar,name_en,name_ku,city',
                'transporter:id,name,phone',
                'orders' => fn ($orders) => $orders
                    ->with([
                        'merchant:id,name,shop_name,phone',
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
        $transfers->through(fn (BranchTransfer $transfer) => $this->transferPayload($transfer));

        return Inertia::render('Admin/Transfers', [
            'transfers' => $transfers,
            'filter' => $data['status'] ?? 'all',
            'q' => $data['q'] ?? '',
            'branches' => Branch::withoutGlobalScope(TenantScope::class)
                ->where('is_active', true)
                ->orderBy('city')
                ->orderBy('name_ar')
                ->get(['id', 'tenant_id', 'is_platform_managed', 'code', 'name_ar', 'name_en', 'name_ku', 'city'])
                ->map(fn (Branch $branch) => $this->branchPayload($branch) + [
                    'tenant_id' => $branch->tenant_id,
                    'is_platform_managed' => (bool) $branch->is_platform_managed,
                ]),
            'transporters' => User::withoutGlobalScopes()
                ->where('role', 'transporter')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'phone'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone]),
            'eligible_orders' => $this->eligibleOrders(),
            'counts' => [
                'all' => BranchTransfer::withoutGlobalScope(TenantScope::class)->count(),
                BranchTransfer::DRAFT => BranchTransfer::withoutGlobalScope(TenantScope::class)->where('status', BranchTransfer::DRAFT)->count(),
                BranchTransfer::DISPATCHED => BranchTransfer::withoutGlobalScope(TenantScope::class)->where('status', BranchTransfer::DISPATCHED)->count(),
                BranchTransfer::RECEIVED => BranchTransfer::withoutGlobalScope(TenantScope::class)->where('status', BranchTransfer::RECEIVED)->count(),
            ],
        ]);
    }

    public function store(Request $request, BranchTransferService $transfers)
    {
        $data = $request->validate([
            'origin_branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'destination_branch_id' => ['required', 'integer', 'different:origin_branch_id', Rule::exists('branches', 'id')],
            'transporter_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'integer', 'distinct', Rule::exists('orders', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $transfers->create($request->user(), $data);

        return back()->with('success', __('Transfer created successfully.'));
    }

    public function dispatch(Request $request, int $transfer, BranchTransferService $transfers)
    {
        $transfers->dispatch($transfer, $request->user());

        return back()->with('success', __('Transfer dispatched successfully.'));
    }

    public function receive(Request $request, int $transfer, BranchTransferService $transfers)
    {
        $transfers->receive($transfer, $request->user());

        return back()->with('success', __('Transfer received successfully.'));
    }

    /** @return array<int, array<string, mixed>> */
    private function eligibleOrders(): array
    {
        $activeTransferOrderIds = DB::table('branch_transfer_orders as transfer_orders')
            ->join('branch_transfers as transfers', 'transfers.id', '=', 'transfer_orders.branch_transfer_id')
            ->whereIn('transfers.status', [BranchTransfer::DRAFT, BranchTransfer::DISPATCHED])
            ->pluck('transfer_orders.order_id');

        return Order::withoutGlobalScope(TenantScope::class)
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
            ->limit(250)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'tenant_id' => $order->tenant_id,
                'tenant' => $order->tenant?->name,
                'track_no' => $order->track_no,
                'customer' => $order->customer_name_ar,
                'merchant' => $order->merchant?->shop_name ?: $order->merchant?->name,
                'price' => (int) $order->price,
                'status' => $order->status,
                'workflow_stage' => $order->workflow_stage,
                'origin_branch_id' => $order->origin_branch_id,
                'destination_branch_id' => $order->destination_branch_id,
                'origin_branch' => $this->branchPayload($order->originBranch),
                'destination_branch' => $this->branchPayload($order->destinationBranch),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function transferPayload(BranchTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'reference' => $transfer->reference,
            'status' => $transfer->status,
            'notes' => $transfer->notes,
            'created_at' => $transfer->created_at?->toIso8601String(),
            'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            'received_at' => $transfer->received_at?->toIso8601String(),
            'origin_branch' => $this->branchPayload($transfer->originBranch),
            'destination_branch' => $this->branchPayload($transfer->destinationBranch),
            'transporter' => $transfer->transporter ? [
                'id' => $transfer->transporter->id,
                'name' => $transfer->transporter->name,
                'phone' => $transfer->transporter->phone,
            ] : null,
            'orders' => $transfer->orders->map(fn (Order $order) => [
                'id' => $order->id,
                'track_no' => $order->track_no,
                'customer' => $order->customer_name_ar,
                'merchant' => $order->merchant?->shop_name ?: $order->merchant?->name ?: $order->tenant?->name,
                'tenant' => $order->tenant?->name,
                'price' => (int) $order->price,
                'status' => $order->status,
                'workflow_stage' => $order->workflow_stage,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function branchPayload(?Branch $branch): ?array
    {
        return $branch ? [
            'id' => $branch->id,
            'code' => $branch->code,
            'name_ar' => $branch->name_ar,
            'name_en' => $branch->name_en,
            'name_ku' => $branch->name_ku,
            'city' => $branch->city,
        ] : null;
    }
}
