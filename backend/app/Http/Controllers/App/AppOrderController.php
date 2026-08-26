<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\OrderStatusLog;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\User;
use App\Services\CourierOrderAccess;
use App\Services\CourierOrderAssignmentService;
use App\Services\OrderWorkflowService;
use App\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AppOrderController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'filter' => ['nullable', Rule::in(array_merge(['all'], Order::STATUSES))],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $isCourier = $request->user()->role === 'courier';

        $baseQuery = $isCourier
            ? app(CourierOrderAccess::class)->assigned($request->user())
            : Order::query();
        $query = clone $baseQuery;

        $filter = $request->input('filter', 'all');
        $q = $request->input('q');

        if ($filter !== 'all') {
            $query->where('status', $filter);
        }

        if ($q) {
            $query->where(function ($builder) use ($q) {
                $builder->where('track_no', 'like', "%{$q}%")
                    ->orWhere('customer_name_ar', 'like', "%{$q}%")
                    ->orWhere('customer_name_en', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $orderRecords = $query->with([
            'courier:id,name,phone',
            'merchant:id,name,phone,address',
            'tenant:id,name',
            // Route branches can belong to the platform operations tenant,
            // so the relations intentionally resolve outside the viewer's
            // tenant scope. The order itself has already passed the merchant
            // or assigned-courier visibility query above.
            'originBranch:id,name_ar,name_en,name_ku,city',
            'destinationBranch:id,name_ar,name_en,name_ku,city',
            'statusLogs' => fn ($logs) => $logs
                ->with('user:id,name,phone,role')
                ->latest('created_at'),
            'movements' => fn ($movements) => $movements
                // A courier belongs to a different tenant from the merchant
                // order. Its auditable movement history must not disappear
                // merely because the courier is viewing an assigned order.
                ->withoutGlobalScope(TenantScope::class)
                ->with('actor:id,name,phone,role')
                ->latest('occurred_at'),
        ])->latest('id')->get();

        // Order movements retain the branch ids that were active when an
        // event occurred. Resolve that historical route independently of the
        // current viewer's tenant so the timeline remains truthful after a
        // cross-network assignment.
        $movementBranchIds = $orderRecords
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

        $orders = $orderRecords->map(fn (Order $o) => [
            'id' => $o->id,
            'track_no' => $o->track_no,
            'source' => $o->source,
            'customer_name_ar' => $o->customer_name_ar,
            'customer_name_en' => $o->customer_name_en,
            'phone' => $o->phone,
            'phone2' => $o->phone2,
            'address_ar' => $o->address_ar,
            'address_en' => $o->address_en,
            'order_type' => $o->order_type,
            'delivery_vehicle' => $o->delivery_vehicle,
            'vehicle_note' => $o->vehicle_note,
            'province_id' => $o->province_id,
            'price' => $o->price,
            'fee' => $o->fee,
            'status' => $o->status,
            'workflow_stage' => $o->workflow_stage,
            'date' => $o->date->toDateString(),
            'created_at' => $this->timestamp($o->created_at),
            'updated_at' => $this->timestamp($o->updated_at),
            'notes' => $o->notes,
            'pickup_deadline_at' => $o->pickup_deadline_at?->toIso8601String(),
            'courier' => $o->courier ? ['name' => $o->courier->name, 'phone' => $o->courier->phone] : null,
            'merchant' => $o->merchant
                ? ['name' => $o->merchant->name, 'phone' => $o->merchant->phone, 'address' => $o->merchant->address]
                : ($o->tenant ? ['name' => $o->tenant->name, 'phone' => null, 'address' => null] : null),
            'courier_id' => $o->courier_id,
            'origin_branch' => $o->originBranch
                ? $this->branchPayload($o->originBranch)
                : null,
            'destination_branch' => $o->destinationBranch
                ? $this->branchPayload($o->destinationBranch)
                : null,
            'timeline' => $this->timelinePayload($o, $movementBranches),
        ])->values();

        $counts = $isCourier
            ? [
                'all' => (clone $baseQuery)->count(),
                'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
                'approved' => (clone $baseQuery)->where('status', 'approved')->count(),
                'courier' => (clone $baseQuery)->where('status', 'courier')->count(),
                'delivered' => (clone $baseQuery)->where('status', 'delivered')->count(),
                'returned' => (clone $baseQuery)->where('status', 'returned')->count(),
            ]
            : $this->counts();

        return Inertia::render('Mobile/Orders', [
            'orders' => $orders,
            'counts' => $counts,
            'filter' => $filter,
            'q' => $q,
            'isCourier' => $isCourier,
            'wallet' => $this->walletData($request->user()),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'phone2' => ['nullable', 'string', 'max:30'],
            'address_ar' => ['required', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'delivery_vehicle' => ['required', Rule::in(['normal', 'bike', 'sedan', 'suv', 'truck'])],
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = TenantContext::tenant();
        $user = $request->user();

        abort_unless(
            $user->provinces()->whereKey($data['province_id'])->exists(),
            422,
            'المحافظة المختارة غير مفعلة لحسابك.'
        );

        $order = new Order($data);
        $order->tenant_id = $tenant->id;
        $order->source = 'merchant';
        $order->customer_name_en = $request->input('customer_name_en') ?: $data['customer_name_ar'];
        $order->address_en = $request->input('address_en') ?: $data['address_ar'];
        $order->track_no = 'ALM-'.mt_rand(100000, 999999);
        $order->date = $request->input('date') ?: today();
        $order->status = 'pending';
        $order->workflow_stage = 'created';
        $order->province_id = $data['province_id'];
        // These operational defaults are controlled from the dashboard.  A
        // merchant can never override them in a browser request.
        $availabilityMinutes = max(1, min((int) Setting::get('order_expiry_minutes', 30), 1440));
        $order->fee = max(0, min((int) Setting::get('delivery_fee', 0), 1_000_000));
        $order->pickup_deadline_at = now()->addMinutes($availabilityMinutes);
        $order->merchant_id = $user->id;
        $order->created_by = $user->id;
        $order->save();

        OrderStatusLog::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'pending',
            'user_id' => $user->id,
        ]);

        ActivityLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'action' => 'order.created',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'data' => ['track_no' => $order->track_no],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('orders.created', ['track' => $order->track_no]));
    }

    public function update(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        abort_unless($order->status === 'pending', 422, 'يمكن تعديل الطلبات قيد الانتظار فقط.');

        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'phone2' => ['nullable', 'string', 'max:30'],
            'address_ar' => ['required', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'delivery_vehicle' => ['required', Rule::in(['normal', 'bike', 'sedan', 'suv', 'truck'])],
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        abort_unless(
            $request->user()->provinces()->whereKey($data['province_id'])->exists(),
            422,
            'المحافظة المختارة غير مفعلة لحسابك.'
        );

        $order->update([
            ...$data,
            'customer_name_en' => $request->input('customer_name_en') ?: $data['customer_name_ar'],
            'address_en' => $request->input('address_en') ?: $data['address_ar'],
        ]);

        return back()->with('success', __('orders.updated', ['track' => $order->track_no]));
    }

    public function status(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);

        $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $to = $request->input('status');
        $user = $request->user();

        abort_if($user->role === 'merchant', 403, 'لا يمكن للتاجر تغيير مرحلة التوصيل.');

        if ($user->role === 'courier') {
            $allowed = match ($order->status) {
                'approved' => ['courier'],
                'courier' => ['delivered', 'returned'],
                default => [],
            };

            abort_unless(in_array($to, $allowed, true), 422, 'انتقال حالة الطلب غير مسموح.');
        }

        app(OrderWorkflowService::class)->changeStatus($order, $to, $user, $request->input('note'));

        return back()->with('success', __('orders.status_changed'));
    }

    public function claim(Request $request, Order $order)
    {
        $courier = $request->user();

        abort_unless($courier->role === 'courier', 403);

        app(CourierOrderAssignmentService::class)->assign(
            $order,
            $courier,
            $courier,
            'تم قبول الطلب من المندوب.',
            requireOnDuty: true,
        );

        return back()->with('success', 'تم قبول الطلب وخصم قيمته من ميزانية المندوب.');
    }

    protected function authorizeOrder(Order $order, Request $request): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        if ($request->user()->role === 'courier') {
            abort_unless($order->courier_id === $request->user()->id, 403);
        }

        if ($request->user()->role === 'merchant') {
            abort_unless($order->tenant_id === $request->user()->tenant_id, 403);
        }
    }

    /**
     * Build a mobile-safe operational history from records that were actually
     * written by the workflow service or the branch-routing screen. The
     * client deliberately receives events, not an invented progress path:
     * an unperformed transfer is never shown as completed.
     */
    private function timelinePayload(Order $order, $movementBranches): array
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
                'stage' => $isCreated ? 'created' : null,
                'note' => $log->note,
                'actor' => $this->personPayload($log->user),
                'from_branch' => null,
                'to_branch' => null,
                'at' => $this->timestamp($log->created_at),
            ]);
        }

        // Older orders may predate status logging. Their creation time is a
        // real event, so preserve it rather than leaving the timeline blank.
        if (! $hasCreationLog && $order->created_at) {
            $events->push([
                'kind' => 'created',
                'status' => 'pending',
                'from_status' => null,
                'stage' => 'created',
                'note' => null,
                'actor' => $this->personPayload($order->merchant),
                'from_branch' => null,
                'to_branch' => null,
                'at' => $this->timestamp($order->created_at),
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
                'at' => $this->timestamp($movement->occurred_at),
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
            'role' => $user->role,
        ] : null;
    }

    private function branchPayload(?Branch $branch): ?array
    {
        return $branch ? [
            'id' => $branch->id,
            'name' => $branch->name_ar,
            'name_ar' => $branch->name_ar,
            'name_en' => $branch->name_en,
            'name_ku' => $branch->name_ku,
            'city' => $branch->city,
        ] : null;
    }

    private function timestamp(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return Carbon::parse($value)->toIso8601String();
    }

    protected function counts(): array
    {
        return [
            'all' => Order::query()->count(),
            'pending' => Order::query()->where('status', 'pending')->count(),
            'approved' => Order::query()->where('status', 'approved')->count(),
            'courier' => Order::query()->where('status', 'courier')->count(),
            'delivered' => Order::query()->where('status', 'delivered')->count(),
            'returned' => Order::query()->where('status', 'returned')->count(),
        ];
    }

    protected function walletData(User $user): array
    {
        $wallet = $user->wallet;

        return [
            'balance' => $wallet?->balance ?? 0,
            'budget' => $wallet?->budget ?? 0,
        ];
    }
}
