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
use App\Services\PricingService;
use App\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        $isCourier = $request->user()->isCourierRole();

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
            $query->where(function ($builder) use ($q, $isCourier) {
                $builder->where('track_no', 'like', "%{$q}%")
                    ->orWhere('customer_name_ar', 'like', "%{$q}%")
                    ->orWhere('customer_name_en', 'like', "%{$q}%");

                // A courier cannot use search as a side channel to probe a
                // customer's number before the order is physically with them.
                if (! $isCourier) {
                    $builder->orWhere('phone', 'like', "%{$q}%");
                }
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

        $orders = $orderRecords->map(function (Order $o) use ($isCourier, $movementBranches): array {
            return [
            'id' => $o->id,
            'track_no' => $o->track_no,
            'source' => $o->source,
            'customer_name_ar' => $o->customer_name_ar,
            'customer_name_en' => $o->customer_name_en,
            // The merchant and the assigned/eligible courier need customer
            // contact details at every delivery state, including pending.
            'phone' => $o->phone,
            'phone2' => $o->phone2,
            'phone_revealed' => true,
            'address_ar' => $o->address_ar,
            'address_en' => $o->address_en,
            'pickup_latitude' => $o->pickup_latitude === null ? null : (float) $o->pickup_latitude,
            'pickup_longitude' => $o->pickup_longitude === null ? null : (float) $o->pickup_longitude,
            'pickup_location_label' => $o->pickup_location_label,
            'order_type' => $o->order_type,
            'delivery_vehicle' => $o->delivery_vehicle,
            'weight_grams' => (int) ($o->weight_grams ?? 0),
            'vehicle_note' => $o->vehicle_note,
            'province_id' => $o->province_id,
            'price' => $o->price,
            'fee' => $o->fee,
            // The pricing quote stays immutable; the return flow exposes the
            // separately selected amount only after a courier chooses it.
            'return_fee' => (int) ($o->return_fee ?? 0),
            'return_fee_applied' => (int) ($o->return_fee_applied ?? 0),
            'pricing_rule_id' => $o->pricing_rule_id,
            'status' => $o->status,
            'workflow_stage' => $o->workflow_stage,
            'date' => $o->date->toDateString(),
            'created_at' => $this->timestamp($o->created_at),
            'updated_at' => $this->timestamp($o->updated_at),
            'returned_at' => $this->timestamp($o->returned_at),
            'returned_to_merchant_at' => $this->timestamp($o->returned_to_merchant_at),
            'return_fee_charged_at' => $this->timestamp($o->return_fee_charged_at),
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
            ];
        })->values();

        $counts = $isCourier
            ? [
                'all' => (clone $baseQuery)->count(),
                'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
                'approved' => (clone $baseQuery)->where('status', 'approved')->count(),
                'courier' => (clone $baseQuery)->where('status', 'courier')->count(),
                'delivered' => (clone $baseQuery)->where('status', 'delivered')->count(),
                'returned' => (clone $baseQuery)->where('status', 'returned')->count(),
                'cancelled' => (clone $baseQuery)->where('status', 'cancelled')->count(),
                'damaged' => (clone $baseQuery)->where('status', 'damaged')->count(),
                'rejected' => (clone $baseQuery)->where('status', 'rejected')->count(),
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
        $user = $request->user();
        abort_unless($user?->role === 'merchant', 403, 'إنشاء الطلبات متاح للتاجر فقط.');

        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'phone2' => ['nullable', 'string', 'max:30'],
            'address_ar' => ['required', 'string', 'max:255'],
            // A courier must always know where to collect a merchant order.
            // Require the whole pickup tuple server-side, not just in the UI.
            'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
            'pickup_location_label' => ['required', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'delivery_vehicle' => ['required', Rule::in(['normal', 'bike', 'sedan', 'suv', 'truck'])],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = TenantContext::tenant();

        $provinceId = $this->operatingProvinceForUser($user);
        abort_unless($provinceId, 422, 'هذا الحساب غير مرتبط بمحافظة تشغيل مفعّلة.');
        $operatingBranch = $this->operatingBranchForUser($user, $provinceId);
        abort_unless($operatingBranch, 422, 'هذا الحساب غير مرتبط بفرع نشط.');

        $order = new Order($data);
        $order->tenant_id = $tenant->id;
        $order->source = 'merchant';
        $order->customer_name_en = $request->input('customer_name_en') ?: $data['customer_name_ar'];
        $order->address_en = $request->input('address_en') ?: $data['address_ar'];
        $order->track_no = 'ALM-'.mt_rand(100000, 999999);
        $order->date = $request->input('date') ?: today();
        $order->status = 'pending';
        $order->workflow_stage = 'created';
        $order->province_id = $provinceId;
        // These operational defaults are controlled from the dashboard.  A
        // merchant can never override them in a browser request.
        $availabilityMinutes = max(1, min((int) Setting::get('order_expiry_minutes', 30), 1440));
        $quote = app(PricingService::class)->quote(
            $user,
            $provinceId,
            $data['order_type'] ?? null,
            $data['delivery_vehicle'],
            (int) ($data['weight_grams'] ?? 0),
            max(0, min((int) Setting::get('delivery_fee', 0), 1_000_000)),
        );
        $order->weight_grams = (int) ($data['weight_grams'] ?? 0);
        $order->fee = $quote['fee'];
        $order->return_fee = $quote['return_fee'];
        $order->pricing_rule_id = $quote['rule']?->id;
        $order->pickup_deadline_at = now()->addMinutes($availabilityMinutes);
        $order->merchant_id = $user->id;
        $order->created_by = $user->id;
        $order->branch_id = $operatingBranch->id;
        $order->origin_branch_id = $operatingBranch->id;
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
            'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
            'pickup_location_label' => ['required', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'delivery_vehicle' => ['required', Rule::in(['normal', 'bike', 'sedan', 'suv', 'truck'])],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $provinceId = $this->operatingProvinceForUser($request->user());
        abort_unless($provinceId, 422, 'هذا الحساب غير مرتبط بمحافظة تشغيل مفعّلة.');
        $operatingBranch = $this->operatingBranchForUser($request->user(), $provinceId);
        abort_unless($operatingBranch, 422, 'هذا الحساب غير مرتبط بفرع نشط.');

        $quote = app(PricingService::class)->quote(
            $request->user(),
            $provinceId,
            $data['order_type'] ?? null,
            $data['delivery_vehicle'],
            (int) ($data['weight_grams'] ?? 0),
            max(0, min((int) Setting::get('delivery_fee', 0), 1_000_000)),
        );

        $order->update([
            ...$data,
            'customer_name_en' => $request->input('customer_name_en') ?: $data['customer_name_ar'],
            'address_en' => $request->input('address_en') ?: $data['address_ar'],
            'weight_grams' => (int) ($data['weight_grams'] ?? 0),
            'fee' => $quote['fee'],
            'return_fee' => $quote['return_fee'],
            'pricing_rule_id' => $quote['rule']?->id,
            'province_id' => $provinceId,
            'branch_id' => $operatingBranch->id,
            'origin_branch_id' => $operatingBranch->id,
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

        if ($user->isCourierRole()) {
            $allowed = match ($user->role) {
                'courier' => match ($order->status) {
                    'approved' => ['courier'],
                    // Returns are deliberately handled by the two-step
                    // endpoint below so a fee and physical handback cannot
                    // be skipped with a generic status post.
                    'courier' => ['delivered'],
                    default => [],
                },
                'pickup_courier' => $order->status === 'approved' ? ['courier'] : [],
                'delivery_courier' => $order->status === 'courier' ? ['delivered'] : [],
                default => [],
            };

            abort_unless(in_array($to, $allowed, true), 422, 'انتقال حالة الطلب غير مسموح.');
        }

        app(OrderWorkflowService::class)->changeStatus($order, $to, $user, $request->input('note'));

        return back()->with('success', __('orders.status_changed'));
    }

    public function startReturn(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        abort_unless($request->user()->role === 'courier', 403, 'إرجاع الطلب متاح للمندوب المعيّن فقط.');

        $data = $request->validate([
            'fee_mode' => ['required', Rule::in(['none', 'fee'])],
            'return_fee_applied' => ['nullable', 'integer', 'min:1', 'max:1000000', Rule::requiredIf($request->input('fee_mode') === 'fee')],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        app(OrderWorkflowService::class)->startCourierReturn(
            $order,
            $request->user(),
            $data['fee_mode'] === 'fee' ? (int) $data['return_fee_applied'] : 0,
            $data['note'] ?? null,
        );

        return back()->with('success', 'تم تسجيل الإرجاع. أكّد تسليم الطلب إلى التاجر بعد إتمامه فعلياً.');
    }

    public function confirmReturnToMerchant(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        abort_unless($request->user()->role === 'courier', 403, 'تأكيد الإرجاع متاح للمندوب المعيّن فقط.');

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        app(OrderWorkflowService::class)->confirmCourierReturnToMerchant(
            $order,
            $request->user(),
            $data['note'] ?? null,
        );

        return back()->with('success', 'تم تأكيد إعادة الطلب إلى التاجر وتحديث السجل التشغيلي والمالي.');
    }

    /**
     * A returned parcel remains historical evidence.  The merchant can start
     * a new delivery from it, but never mutate the completed return back into
     * an active order.  This keeps both the archive and financial trail
     * truthful while sparing the merchant from retyping the order details.
     */
    public function recreate(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        abort_unless($request->user()->role === 'merchant', 403, 'إعادة إنشاء الطلب متاحة للتاجر فقط.');
        abort_unless($order->status === 'returned', 422, 'يمكن إعادة إنشاء الطلبات المرتجعة فقط.');

        $newOrder = DB::transaction(function () use ($order, $request): Order {
            $new = $order->replicate([
                'track_no', 'status', 'workflow_stage', 'courier_id',
                'pickup_courier_id', 'delivery_courier_id', 'branch_id',
                'origin_branch_id', 'destination_branch_id', 'accepted_at',
                'picked_at', 'delivered_at', 'returned_at',
                'returned_to_merchant_at', 'return_fee_applied',
                'return_fee_charged_at', 'pickup_deadline_at',
            ]);

            $new->forceFill([
                'track_no' => 'ALM-'.mt_rand(100000, 999999),
                'merchant_id' => $request->user()->id,
                'created_by' => $request->user()->id,
                'status' => 'pending',
                'workflow_stage' => 'created',
                'courier_id' => null,
                'pickup_courier_id' => null,
                'delivery_courier_id' => null,
                'branch_id' => null,
                'origin_branch_id' => null,
                'destination_branch_id' => null,
                'date' => today(),
                'accepted_at' => null,
                'picked_at' => null,
                'delivered_at' => null,
                'returned_at' => null,
                'returned_to_merchant_at' => null,
                'return_fee_applied' => 0,
                'return_fee_charged_at' => null,
                'pickup_deadline_at' => now()->addMinutes(max(1, min((int) Setting::get('order_expiry_minutes', 30), 1440))),
            ]);
            $new->save();

            OrderStatusLog::create([
                'tenant_id' => $new->tenant_id,
                'order_id' => $new->id,
                'from_status' => null,
                'to_status' => 'pending',
                'user_id' => $request->user()->id,
                'note' => 'إعادة إنشاء من الطلب المرتجع '.$order->track_no,
            ]);

            return $new;
        });

        ActivityLog::create([
            'tenant_id' => $newOrder->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'order.recreated_from_return',
            'subject_type' => 'order',
            'subject_id' => $newOrder->id,
            'data' => ['source_order_id' => $order->id, 'source_track_no' => $order->track_no],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'تم إنشاء طلب جديد من الطلب المرتجع: '.$newOrder->track_no);
    }

    /** Re-open an unclaimed pending order for a fresh courier offer window. */
    public function republish(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        abort_unless($request->user()->role === 'merchant', 403, 'إعادة نشر الطلب متاحة للتاجر فقط.');
        abort_unless($order->status === 'pending' && ! $order->courier_id, 422, 'يمكن إعادة نشر الطلبات قيد الانتظار وغير المقبولة فقط.');

        $minutes = max(1, min((int) Setting::get('order_expiry_minutes', 30), 1440));
        $order->forceFill(['pickup_deadline_at' => now()->addMinutes($minutes)])->save();

        ActivityLog::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'order.republished',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'data' => ['track_no' => $order->track_no],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'تمت إعادة نشر الطلب للمندوبين المتاحين.');
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

        if ($request->user()->isCourierRole()) {
            abort_unless(
                app(CourierOrderAccess::class)
                    ->assigned($request->user())
                    ->whereKey($order->id)
                    ->exists(),
                403,
            );
        }

        if ($request->user()->role === 'merchant') {
            abort_unless($order->tenant_id === $request->user()->tenant_id, 403);
        }
    }

    private function operatingBranchForUser(User $user, int $provinceId): ?Branch
    {
        $branchId = (int) $user->branch_id;

        $branches = Branch::withoutGlobalScopes()
            ->where('tenant_id', \App\Models\Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->where('province_id', $provinceId);

        if ($branchId > 0) {
            return $branches->whereKey($branchId)->first();
        }

        // Legacy accounts created before branch selection was introduced may
        // not have a branch assignment. The first active branch for their
        // authorised province is a safe one-time compatibility fallback.
        return $branches->orderBy('name_ar')->first();
    }

    private function operatingProvinceForUser(User $user): ?int
    {
        $provinceId = $user->provinces()->value('provinces.id');

        if ($provinceId) {
            return (int) $provinceId;
        }

        return $user->branch_id
            ? Branch::withoutGlobalScopes()->whereKey($user->branch_id)->value('province_id')
            : null;
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
            'cancelled' => Order::query()->where('status', 'cancelled')->count(),
            'damaged' => Order::query()->where('status', 'damaged')->count(),
            'rejected' => Order::query()->where('status', 'rejected')->count(),
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
