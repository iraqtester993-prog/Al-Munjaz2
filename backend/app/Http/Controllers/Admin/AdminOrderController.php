<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\CourierOrderAccess;
use App\Services\CourierOrderAssignmentService;
use App\Services\OrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminOrderController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'filter' => ['nullable', Rule::in(array_merge(['all'], Order::STATUSES))],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = Order::query()->with([
            'courier:id,name,phone,vehicle',
            'pickupCourier:id,name,phone,vehicle',
            'deliveryCourier:id,name,phone,vehicle',
            'merchant:id,name,phone,shop_name,address',
            'tenant:id,name',
            'province:id,name_ar,name_en,name_ku',
            'originBranch:id,name_ar,name_en,name_ku,city',
            'destinationBranch:id,name_ar,name_en,name_ku,city',
            'statusLogs' => fn ($logs) => $logs
                ->with('user:id,name,role')
                ->latest('created_at'),
            'movements' => fn ($movements) => $movements
                ->with('actor:id,name,role')
                ->latest('occurred_at'),
        ]);

        if ($request->input('filter') !== 'all' && $request->filled('filter')) {
            $query->where('status', $request->input('filter'));
        }

        if ($q = $request->input('q')) {
            $query->where(function ($b) use ($q) {
                $b->where('track_no', 'like', "%{$q}%")
                    ->orWhere('customer_name_ar', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $orders = $query->latest('id')->paginate(25);
        $orderCollection = $orders->getCollection();
        $visibleTenantIds = $orderCollection
            ->pluck('tenant_id')
            ->filter()
            ->map(fn ($tenantId) => (int) $tenantId)
            ->unique()
            ->values();

        // Movements retain the branch that was selected at that point in the
        // route. Resolve them without a tenant scope so an administrator can
        // accurately inspect a shared-network route as well as a merchant's
        // own branch history. The order itself has already passed the normal
        // dashboard authorization and tenant visibility query above.
        $movementBranchIds = $orderCollection
            ->flatMap(fn (Order $order) => $order->movements->flatMap(fn (OrderMovement $movement) => [
                $movement->from_branch_id,
                $movement->to_branch_id,
            ]))
            ->filter()
            ->unique()
            ->values();

        $movementBranches = $movementBranchIds->isEmpty()
            ? collect()
            : Branch::withoutGlobalScope(TenantScope::class)
                ->withTrashed()
                ->whereIn('id', $movementBranchIds)
                ->get(['id', 'name_ar', 'name_en', 'name_ku', 'city'])
                ->keyBy('id');

        $orders->through(fn (Order $order) => $this->orderPayload($order, $movementBranches));

        $counts = [
            'all' => Order::query()->count(),
            'pending' => Order::query()->where('status', 'pending')->count(),
            'approved' => Order::query()->where('status', 'approved')->count(),
            'courier' => Order::query()->where('status', 'courier')->count(),
            'delivered' => Order::query()->where('status', 'delivered')->count(),
            'returned' => Order::query()->where('status', 'returned')->count(),
        ];

        $couriers = User::query()
            ->where('role', 'courier')
            ->where('status', 'active')
            ->with('provinces:id,name_ar')
            ->get(['id', 'name', 'phone'])
            ->map(fn (User $courier) => [
                'id' => $courier->id,
                'name' => $courier->name,
                'phone' => $courier->phone,
                'provinces' => $courier->provinces->map(fn ($province) => [
                    'id' => $province->id,
                    'name_ar' => $province->name_ar,
                ])->all(),
            ]);

        // Do not expose every merchant's private branch in every assignment
        // dialog. The global operations network is shared; tenant-owned
        // branches are returned only for merchants represented on this page.
        $branchQuery = Branch::withoutGlobalScope(TenantScope::class)
            ->where('is_active', true);

        if ($visibleTenantIds->isEmpty()) {
            $branchQuery->where('is_platform_managed', true);
        } else {
            $branchQuery->where(function ($branches) use ($visibleTenantIds): void {
                $branches
                    ->where('is_platform_managed', true)
                    ->orWhereIn('tenant_id', $visibleTenantIds->all());
            });
        }

        $branches = $branchQuery
            ->orderBy('name_ar')
            ->get(['id', 'tenant_id', 'code', 'name_ar', 'city', 'is_platform_managed'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'tenant_id' => $branch->tenant_id,
                'code' => $branch->code,
                'name' => $branch->name_ar,
                'city' => $branch->city,
                'is_platform_managed' => $branch->is_platform_managed,
            ]);

        return Inertia::render('Admin/Orders', [
            'orders' => $orders,
            'counts' => $counts,
            'filter' => $request->input('filter', 'all'),
            'q' => $request->input('q', ''),
            'couriers' => $couriers,
            'branches' => $branches,
        ]);
    }

    public function status(Request $request, Order $order)
    {
        $request->validate(['status' => ['required', Rule::in(Order::STATUSES)]]);

        app(OrderWorkflowService::class)->changeStatus($order, $request->input('status'), $request->user(), $request->input('note'));

        return back()->with('success', __('orders.status_changed'));
    }

    public function assignCourier(Request $request, Order $order)
    {
        $request->validate([
            'courier_id' => ['required', 'exists:users,id'],
        ]);

        $courier = User::findOrFail($request->integer('courier_id'));

        abort_unless($courier->role === 'courier' && $courier->status === 'active', 422, 'المستخدم المختار ليس مندوباً نشطاً.');
        abort_unless($order->status === 'pending' && $order->courier_id === null, 422, 'الطلب لم يعد متاحاً للتعيين.');
        abort_unless($order->province_id, 422, 'يجب تحديد محافظة الطلب قبل تعيين المندوب.');
        abort_unless(app(CourierOrderAccess::class)->canServeProvince($courier, (int) $order->province_id), 422, 'هذا المندوب غير مفعّل في محافظة الطلب.');

        app(CourierOrderAssignmentService::class)->assign(
            $order,
            $courier,
            $request->user(),
            'تم تعيين المندوب من لوحة الإدارة.',
        );

        return back()->with('success', __('orders.courier_assigned'));
    }

    public function assignBranches(Request $request, Order $order)
    {
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
     * Keep the dashboard's list and detail sheet driven by one operational
     * payload.  The client never has to infer a route, a ledger amount, or a
     * history item from presentation-only data.
     */
    private function orderPayload(Order $order, $movementBranches): array
    {
        $fee = $order->fee === null ? null : (int) $order->fee;
        $merchant = $order->merchant
            ? [
                'id' => $order->merchant->id,
                'name' => $order->merchant->name,
                'shop_name' => $order->merchant->shop_name,
                'phone' => $order->merchant->phone,
                'address' => $order->merchant->address,
            ]
            : ($order->tenant ? [
                'id' => null,
                'name' => $order->tenant->name,
                'shop_name' => null,
                'phone' => null,
                'address' => null,
            ] : null);

        return [
            'id' => $order->id,
            'track_no' => $order->track_no,
            'source' => $order->source,
            'status' => $order->status,
            'workflow_stage' => $order->workflow_stage,
            'tenant_id' => $order->tenant_id,
            'tenant' => $order->tenant?->name,
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
            'price' => (int) $order->price,
            'fee' => $fee,
            'financial' => [
                'order_value' => (int) $order->price,
                'delivery_fee' => $fee,
                'net_to_merchant' => max(0, (int) $order->price - ($fee ?? 0)),
            ],
            'order_type' => $order->order_type,
            'delivery_vehicle' => $order->delivery_vehicle,
            'vehicle_note' => $order->vehicle_note,
            'notes' => $order->notes,
            'date' => $order->date?->toDateString(),
            'created_at' => $this->iso($order->created_at),
            'updated_at' => $this->iso($order->updated_at),
            'accepted_at' => $this->iso($order->accepted_at),
            'picked_at' => $this->iso($order->picked_at),
            'delivered_at' => $this->iso($order->delivered_at),
            'returned_at' => $this->iso($order->returned_at),
            'pickup_deadline_at' => $this->iso($order->pickup_deadline_at),
            'province_id' => $order->province_id,
            'province' => $order->province ? [
                'id' => $order->province->id,
                'name_ar' => $order->province->name_ar,
                'name_en' => $order->province->name_en,
                'name_ku' => $order->province->name_ku,
            ] : null,
            'merchant' => $merchant,
            'origin_branch_id' => $order->origin_branch_id,
            'destination_branch_id' => $order->destination_branch_id,
            'origin_branch' => $this->branchPayload($order->originBranch),
            'destination_branch' => $this->branchPayload($order->destinationBranch),
            'courier' => $this->personPayload($order->courier),
            'pickup_courier' => $this->personPayload($order->pickupCourier),
            'delivery_courier' => $this->personPayload($order->deliveryCourier),
            'timeline' => $this->timelinePayload($order, $movementBranches, $merchant),
        ];
    }

    private function timelinePayload(Order $order, $movementBranches, ?array $merchant): array
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
                'actor' => $this->personPayload($log->user),
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
                'kind' => 'movement',
                'status' => null,
                'from_status' => null,
                'stage' => $movement->stage,
                'note' => $movement->note,
                'actor' => $this->personPayload($movement->actor),
                'from_branch' => $this->branchPayload($movementBranches->get($movement->from_branch_id)),
                'to_branch' => $this->branchPayload($movementBranches->get($movement->to_branch_id)),
                'at' => $this->iso($movement->occurred_at),
            ]);
        }

        return $events
            ->filter(fn (array $event) => filled($event['at']))
            ->sortByDesc('at')
            ->values()
            ->all();
    }

    private function personPayload(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'vehicle' => $user->vehicle,
            'role' => $user->role,
        ] : null;
    }

    private function branchPayload(?Branch $branch): ?array
    {
        return $branch ? [
            'id' => $branch->id,
            'name' => $branch->name_ar,
            'name_en' => $branch->name_en,
            'name_ku' => $branch->name_ku,
            'city' => $branch->city,
        ] : null;
    }

    private function iso($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return filled($value)
            ? \Illuminate\Support\Carbon::parse($value)->toIso8601String()
            : null;
    }
}
