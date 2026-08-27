<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerContactVisibility;
use App\Services\CourierOrderAccess;
use App\Services\OrderWorkflowService;
use App\Services\PricingService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Token API for the mobile clients.
 *
 * This controller deliberately uses the same pricing and status writer as
 * the PWA. A token client must not be able to bypass provincial coverage,
 * courier assignment, wallet protections, or the auditable order timeline.
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->activeApiUser($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_merge(['all'], Order::FILTERABLE_STATUSES))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visibleOrders($user)
            ->with('courier:id,name,phone')
            ->latest('id');

        if (($status = $data['status'] ?? null) && $status !== 'all') {
            $query->operationalStatus($status);
        }

        return response()->json(
            $query->paginate(min($request->integer('per_page', 20), 100))
                ->through(fn (Order $order) => $this->orderData($order, $user)),
        );
    }

    /**
     * The shared route binding resolves Orders without TenantScope. A courier
     * can therefore access a merchant-owned delivery only after the explicit
     * participant check in authorizeOrder() below passes.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $this->activeApiUser($request);
        $this->authorizeOrder($user, $order);

        return response()->json([
            'data' => $this->orderData($order->load('courier:id,name,phone', 'statusLogs'), $user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->activeApiUser($request);
        abort_unless($user->role === 'merchant', 403);

        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            'customer_name_en' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'phone2' => ['nullable', 'string', 'max:30'],
            'address_ar' => ['required', 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:255'],
            // The pickup point is part of every merchant order. Keeping this
            // required in the API prevents a client from bypassing the PWA.
            'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
            'pickup_location_label' => ['required', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'delivery_vehicle' => ['required', Rule::in(['normal', 'bike', 'sedan', 'suv', 'truck'])],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = TenantContext::tenant();
        abort_unless(
            $tenant && (int) $tenant->id === (int) $user->tenant_id,
            403,
            'تعذر تحديد نطاق التاجر.',
        );

        // A merchant can create a delivery only in a province activated for
        // that account. Client-provided province ids are never trusted.
        abort_unless(
            $user->provinces()->whereKey($data['province_id'])->exists(),
            422,
            'المحافظة المختارة غير مفعلة لحسابك.',
        );

        $operatingBranch = $this->operatingBranchForUser($user, (int) $data['province_id']);
        abort_unless($operatingBranch, 422, 'اختر محافظة مرتبطة بفرع نشط قبل إنشاء الطلب.');

        $order = DB::transaction(function () use ($data, $request, $tenant, $user, $operatingBranch): Order {
            $availabilityMinutes = max(1, min((int) Setting::get('order_expiry_minutes', 30), 1440));
            $quote = app(PricingService::class)->quote(
                $user,
                (int) $data['province_id'],
                $data['order_type'] ?? null,
                $data['delivery_vehicle'],
                (int) ($data['weight_grams'] ?? 0),
                max(0, min((int) Setting::get('delivery_fee', 0), 1_000_000)),
            );

            $order = Order::create([
                ...$data,
                'tenant_id' => $tenant->id,
                'source' => 'merchant',
                'customer_name_en' => $data['customer_name_en'] ?? $data['customer_name_ar'],
                'address_en' => $data['address_en'] ?? $data['address_ar'],
                'track_no' => $this->nextTrackNumber(),
                'date' => today(),
                'status' => 'pending',
                'workflow_stage' => 'created',
                'weight_grams' => (int) ($data['weight_grams'] ?? 0),
                // Pricing is an immutable server quote. A token holder cannot
                // post a discounted fee or a fabricated return fee.
                'fee' => $quote['fee'],
                'return_fee' => $quote['return_fee'],
                'pricing_rule_id' => $quote['rule']?->id,
                'pickup_deadline_at' => now()->addMinutes($availabilityMinutes),
                'merchant_id' => $user->id,
                'created_by' => $user->id,
                'branch_id' => $operatingBranch->id,
                'origin_branch_id' => $operatingBranch->id,
            ]);

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
                'data' => ['track_no' => $order->track_no, 'via' => 'api'],
                'ip' => $request->ip(),
            ]);

            return $order;
        });

        return response()->json(['data' => $this->orderData($order, $user)], 201);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $user = $this->activeApiUser($request);
        $this->authorizeOrder($user, $order);

        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        abort_if($user->role === 'merchant', 403, 'لا يمكن للتاجر تغيير مرحلة التوصيل.');

        if ($user->role === 'courier') {
            // A general courier may be listed in a specialist slot for
            // planning purposes, but that must not let them take over the
            // primary delivery lifecycle. Only courier_id owns this generic
            // status endpoint.
            $this->authorizeCourierMutation($user, $order);

            // A courier cannot jump from approval to delivery or register a
            // return here. Returns have their own two-step confirmation flow
            // below, exactly like the PWA.
            $allowed = match ($order->status) {
                'approved' => ['courier'],
                'courier' => ['delivered'],
                default => [],
            };

            abort_unless(in_array($data['status'], $allowed, true), 422, 'انتقال حالة الطلب غير مسموح.');
        } else {
            abort_unless($user->isAdmin(), 403);
        }

        app(OrderWorkflowService::class)->changeStatus($order, $data['status'], $user, $data['note'] ?? null);

        return response()->json([
            'data' => $this->orderData($this->findOrder($order->id)->load('courier:id,name,phone'), $user),
        ]);
    }

    public function startReturn(Request $request, Order $order): JsonResponse
    {
        $user = $this->activeApiUser($request);
        $this->authorizeCourierMutation($user, $order);

        $data = $request->validate([
            'fee_mode' => ['required', Rule::in(['none', 'fee'])],
            'return_fee_applied' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000000',
                Rule::requiredIf($request->input('fee_mode') === 'fee'),
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        app(OrderWorkflowService::class)->startCourierReturn(
            $order,
            $user,
            $data['fee_mode'] === 'fee' ? (int) $data['return_fee_applied'] : 0,
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => $this->orderData($this->findOrder($order->id)->load('courier:id,name,phone'), $user),
        ]);
    }

    public function confirmReturnToMerchant(Request $request, Order $order): JsonResponse
    {
        $user = $this->activeApiUser($request);
        $this->authorizeCourierMutation($user, $order);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        app(OrderWorkflowService::class)->confirmCourierReturnToMerchant(
            $order,
            $user,
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => $this->orderData($this->findOrder($order->id)->load('courier:id,name,phone'), $user),
        ]);
    }

    private function activeApiUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user && $user->isActiveUser(), 403);

        return $user;
    }

    private function visibleOrders(User $user): Builder
    {
        if ($user->isAdmin()) {
            return Order::withoutGlobalScope(TenantScope::class);
        }

        if ($user->role === 'merchant') {
            return Order::query()->where('tenant_id', $user->tenant_id);
        }

        if ($user->role === 'courier') {
            // Start from the shared courier policy, then include the two
            // explicit specialist slots a general courier is allowed to fill.
            return app(CourierOrderAccess::class)
                ->assigned($user)
                ->orWhere('pickup_courier_id', $user->id)
                ->orWhere('delivery_courier_id', $user->id);
        }

        if ($user->role === 'pickup_courier') {
            return Order::withoutGlobalScope(TenantScope::class)
                ->where('pickup_courier_id', $user->id);
        }

        if ($user->role === 'delivery_courier') {
            return Order::withoutGlobalScope(TenantScope::class)
                ->where('delivery_courier_id', $user->id);
        }

        // Transporters operate branch transfers, not orders. Owner, support,
        // and branch-manager API access must be introduced as explicit
        // read-only policies rather than falling through to all records.
        abort(403);
    }

    private function findOrder(int $id): Order
    {
        return Order::withoutGlobalScope(TenantScope::class)->findOrFail($id);
    }

    private function authorizeOrder(User $user, Order $order): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->role === 'merchant') {
            abort_unless((int) $order->tenant_id === (int) $user->tenant_id, 403);

            return;
        }

        if ($user->role === 'courier') {
            abort_unless(
                in_array((int) $user->id, [
                    (int) $order->courier_id,
                    (int) $order->pickup_courier_id,
                    (int) $order->delivery_courier_id,
                ], true),
                403,
            );

            return;
        }

        if ($user->role === 'pickup_courier') {
            abort_unless((int) $order->pickup_courier_id === (int) $user->id, 403);

            return;
        }

        if ($user->role === 'delivery_courier') {
            abort_unless((int) $order->delivery_courier_id === (int) $user->id, 403);

            return;
        }

        abort(403);
    }

    private function authorizeCourierMutation(User $user, Order $order): void
    {
        // Return confirmation affects cash and a merchant's balance. Only a
        // general courier assigned as the primary courier can perform it.
        abort_unless($user->role === 'courier' && (int) $order->courier_id === (int) $user->id, 403);
    }

    private function nextTrackNumber(): string
    {
        do {
            $track = 'ALM-'.random_int(100000, 999999);
        } while (Order::withoutGlobalScope(TenantScope::class)->where('track_no', $track)->exists());

        return $track;
    }

    private function operatingBranchForUser(User $user, int $provinceId): ?Branch
    {
        $branches = Branch::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->where('province_id', $provinceId);

        if ((int) $user->branch_id > 0) {
            return $branches->whereKey($user->branch_id)->first();
        }

        // Compatibility for pre-branch accounts: choose only the first
        // active platform branch in an explicitly authorised province.
        return $branches->orderBy('name_ar')->first();
    }

    private function orderData(Order $order, User $viewer): array
    {
        $phoneRevealed = app(CustomerContactVisibility::class)->canReveal($order, $viewer);

        return [
            'id' => $order->id,
            'track_no' => $order->track_no,
            'source' => $order->source,
            'customer_name' => $order->customer_name_ar,
            'customer_name_ar' => $order->customer_name_ar,
            'customer_name_en' => $order->customer_name_en,
            'phone' => $phoneRevealed ? $order->phone : null,
            'phone2' => $phoneRevealed ? $order->phone2 : null,
            'phone_revealed' => $phoneRevealed,
            'address' => $order->address_ar,
            'address_ar' => $order->address_ar,
            'address_en' => $order->address_en,
            'pickup_latitude' => $order->pickup_latitude === null ? null : (float) $order->pickup_latitude,
            'pickup_longitude' => $order->pickup_longitude === null ? null : (float) $order->pickup_longitude,
            'pickup_location_label' => $order->pickup_location_label,
            'order_type' => $order->order_type,
            'delivery_vehicle' => $order->delivery_vehicle,
            'weight_grams' => (int) ($order->weight_grams ?? 0),
            'vehicle_note' => $order->vehicle_note,
            'price' => (int) $order->price,
            'fee' => (int) ($order->fee ?? 0),
            'return_fee' => (int) ($order->return_fee ?? 0),
            'return_fee_applied' => (int) ($order->return_fee_applied ?? 0),
            'pricing_rule_id' => $order->pricing_rule_id,
            'status' => $order->status,
            'workflow_stage' => $order->workflow_stage,
            'notes' => $order->notes,
            'province_id' => $order->province_id,
            'date' => $order->date?->toDateString(),
            'pickup_deadline_at' => $order->pickup_deadline_at?->toIso8601String(),
            'returned_at' => $order->returned_at?->toIso8601String(),
            'returned_to_merchant_at' => $order->returned_to_merchant_at?->toIso8601String(),
            'courier_id' => $order->courier_id,
            'pickup_courier_id' => $order->pickup_courier_id,
            'delivery_courier_id' => $order->delivery_courier_id,
            'courier' => $order->courier ? [
                'id' => $order->courier->id,
                'name' => $order->courier->name,
                'phone' => $order->courier->phone,
            ] : null,
        ];
    }
}
