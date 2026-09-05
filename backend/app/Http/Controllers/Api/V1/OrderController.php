<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\IraqiMobilePhone;
use App\Services\BranchSettingsResolver;
use App\Services\CourierLocationService;
use App\Services\CourierOrderAccess;
use App\Services\CustomerContactVisibility;
use App\Services\OrderNumberService;
use App\Services\OrderWorkflowService;
use App\Services\PricingService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            // The extra relations are read only and are used only to render
            // one courier for an old split-assignment record. They are never
            // returned as separate operational fields.
            ->with([
                'courier:id,name,phone',
                'pickupCourier:id,name,phone',
                'deliveryCourier:id,name,phone',
            ])
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
            'data' => $this->orderData($order->load([
                'courier:id,name,phone',
                'pickupCourier:id,name,phone',
                'deliveryCourier:id,name,phone',
                'statusLogs',
            ]), $user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->activeApiUser($request);
        abort_unless($user->role === 'merchant', 403);
        $merchantPickup = $this->merchantPickupSnapshot($user);
        $hasSubmittedPickup = $this->hasSubmittedPickupLocation($request);
        $pickupIsRequired = $hasSubmittedPickup || ! $merchantPickup;

        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            'customer_name_en' => ['nullable', 'string', 'max:120'],
            'phone' => ['bail', 'required', 'string', new IraqiMobilePhone],
            'phone2' => ['bail', 'nullable', 'string', new IraqiMobilePhone],
            'address_ar' => ['required', 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:255'],
            // The saved shop point is a default. A complete explicit tuple
            // is a valid one-order override, but any partial tuple is invalid.
            'pickup_latitude' => [$pickupIsRequired ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => [$pickupIsRequired ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
            'pickup_location_label' => [$pickupIsRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'delivery_vehicle' => ['required', Rule::in(['normal', 'bike', 'sedan', 'suv', 'truck'])],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Snapshot either the selected one-order point or, when omitted, the
        // saved shop point. Later profile changes never move this order.
        $data = [...$data, ...$this->resolvedPickupLocation($request, $data, $merchantPickup)];

        $tenant = TenantContext::tenant();
        abort_unless(
            $tenant && (int) $tenant->id === (int) $user->tenant_id,
            403,
            'تعذر تحديد نطاق التاجر.',
        );

        // A merchant can create a delivery only in a province activated for
        // that account. Client-provided province ids are never trusted.
        abort_unless(
            $user->provinces()->active()->whereKey($data['province_id'])->exists(),
            422,
            'المحافظة المختارة غير مفعلة لحسابك.',
        );

        $operatingBranch = $this->operatingBranchForUser($user, (int) $data['province_id']);
        if ((int) $user->branch_id > 0 && ! $operatingBranch) {
            throw ValidationException::withMessages([
                'branch' => 'هذا الحساب غير مرتبط بفرع نشط.',
            ]);
        }

        $order = DB::transaction(function () use ($data, $request, $tenant, $user, $operatingBranch): Order {
            $availabilityMinutes = max(1, min((int) $this->settingForBranch($operatingBranch, 'order_expiry_minutes', 30), 1440));
            $quote = app(PricingService::class)->quote(
                $user,
                (int) $data['province_id'],
                $data['order_type'] ?? null,
                $data['delivery_vehicle'],
                (int) ($data['weight_grams'] ?? 0),
                max(0, min((int) $this->settingForBranch($operatingBranch, 'delivery_fee', 0), 1_000_000)),
            );

            $offerOpenedAt = now();

            $order = Order::create([
                ...$data,
                'tenant_id' => $tenant->id,
                'source' => 'merchant',
                'customer_name_en' => $data['customer_name_en'] ?? $data['customer_name_ar'],
                'address_en' => $data['address_en'] ?? $data['address_ar'],
                'track_no' => app(OrderNumberService::class)->next(),
                'date' => today(),
                'status' => 'pending',
                'workflow_stage' => 'created',
                'weight_grams' => (int) ($data['weight_grams'] ?? 0),
                // Pricing is an immutable server quote. A token holder cannot
                // post a discounted fee or a fabricated return fee.
                'fee' => $quote['fee'],
                'return_fee' => $quote['return_fee'],
                'pricing_rule_id' => $quote['rule']?->id,
                'offer_opened_at' => $offerOpenedAt,
                'pickup_deadline_at' => $offerOpenedAt->copy()->addMinutes($availabilityMinutes),
                'merchant_id' => $user->id,
                'created_by' => $user->id,
                'branch_id' => $operatingBranch?->id,
                'origin_branch_id' => $operatingBranch?->id,
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
            app(CourierLocationService::class)->requireFreshOperationalLocation($user);

            // A courier cannot jump from approval to delivery or register a
            // return here. Returns have their own two-step confirmation flow
            // below, exactly like the PWA.
            $allowed = match ($order->status) {
                'approved' => ['courier'],
                'courier' => ['delivered'],
                default => [],
            };

            if (! in_array($data['status'], $allowed, true)) {
                // Keep the token API aligned with the PWA: a stale or
                // out-of-sequence action is a field validation error, not a
                // bare HTTP exception with no displayable message.
                throw ValidationException::withMessages([
                    'order' => ['انتقال حالة الطلب غير مسموح.'],
                ]);
            }
        } else {
            abort_unless($user->isAdmin(), 403);
        }

        app(OrderWorkflowService::class)->changeStatus($order, $data['status'], $user, $data['note'] ?? null);

        return response()->json([
            'data' => $this->orderData($this->findOrder($order->id)->load([
                'courier:id,name,phone',
                'pickupCourier:id,name,phone',
                'deliveryCourier:id,name,phone',
            ]), $user),
        ]);
    }

    public function startReturn(Request $request, Order $order): JsonResponse
    {
        $user = $this->activeApiUser($request);
        $this->authorizeCourierMutation($user, $order);
        app(CourierLocationService::class)->requireFreshOperationalLocation($user);

        $data = $request->validate([
            'fee_mode' => ['required', Rule::in(['none', 'fee'])],
            'return_reason' => ['required', 'string', 'max:255'],
        ]);

        app(OrderWorkflowService::class)->startCourierReturn(
            $order,
            $user,
            $data['fee_mode'],
            $data['return_reason'],
        );

        return response()->json([
            'data' => $this->orderData($this->findOrder($order->id)->load([
                'courier:id,name,phone',
                'pickupCourier:id,name,phone',
                'deliveryCourier:id,name,phone',
            ]), $user),
        ]);
    }

    public function confirmReturnToMerchant(Request $request, Order $order): JsonResponse
    {
        $user = $this->activeApiUser($request);
        $this->authorizeCourierMutation($user, $order);
        app(CourierLocationService::class)->requireFreshOperationalLocation($user);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        app(OrderWorkflowService::class)->confirmCourierReturnToMerchant(
            $order,
            $user,
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => $this->orderData($this->findOrder($order->id)->load([
                'courier:id,name,phone',
                'pickupCourier:id,name,phone',
                'deliveryCourier:id,name,phone',
            ]), $user),
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
            return app(CourierOrderAccess::class)->assigned($user);
        }

        // Pickup/delivery specialist accounts are retained for historical
        // records only. Direct-order API access belongs to the one courier.
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
                (int) $order->courier_id === (int) $user->id,
                403,
            );

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

    /**
     * @return array{pickup_latitude: float, pickup_longitude: float, pickup_location_label: string}|null
     */
    private function merchantPickupSnapshot(User $merchant): ?array
    {
        if (
            $merchant->merchant_pickup_latitude === null
            || $merchant->merchant_pickup_longitude === null
        ) {
            return null;
        }

        $label = trim((string) $merchant->merchant_pickup_location_label);
        if ($label === '') {
            return null;
        }

        return [
            'pickup_latitude' => round((float) $merchant->merchant_pickup_latitude, 7),
            'pickup_longitude' => round((float) $merchant->merchant_pickup_longitude, 7),
            'pickup_location_label' => $label,
        ];
    }

    private function hasSubmittedPickupLocation(Request $request): bool
    {
        foreach (['pickup_latitude', 'pickup_longitude', 'pickup_location_label'] as $field) {
            if ($request->exists($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{pickup_latitude: float, pickup_longitude: float, pickup_location_label: string}|null  $merchantPickup
     * @return array{pickup_latitude: float, pickup_longitude: float, pickup_location_label: string}
     */
    private function resolvedPickupLocation(Request $request, array $data, ?array $merchantPickup): array
    {
        if (! $this->hasSubmittedPickupLocation($request)) {
            if ($merchantPickup) {
                return $merchantPickup;
            }

            throw ValidationException::withMessages([
                'pickup_latitude' => 'حدّد موقع استلام الطلب قبل الحفظ.',
                'pickup_longitude' => 'حدّد موقع استلام الطلب قبل الحفظ.',
                'pickup_location_label' => 'أدخل وصفاً واضحاً لموقع الاستلام.',
            ]);
        }

        $label = trim((string) ($data['pickup_location_label'] ?? ''));
        if ($label === '') {
            throw ValidationException::withMessages([
                'pickup_location_label' => 'أدخل وصفاً واضحاً لموقع الاستلام.',
            ]);
        }

        return [
            'pickup_latitude' => round((float) $data['pickup_latitude'], 7),
            'pickup_longitude' => round((float) $data['pickup_longitude'], 7),
            'pickup_location_label' => $label,
        ];
    }

    private function operatingBranchForUser(User $user, int $provinceId): ?Branch
    {
        $branches = Branch::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            // withoutGlobalScopes() removes the SoftDeletes scope as well.
            ->whereNull('deleted_at')
            ->where('province_id', $provinceId)
            ->whereHas('province', fn ($province) => $province->platform()->active());

        if ((int) $user->branch_id > 0) {
            return $branches->whereKey($user->branch_id)->first();
        }

        // Compatibility for pre-branch accounts: choose only the first
        // active platform branch in an explicitly authorised province.
        return $branches->orderBy('name_ar')->first();
    }

    private function settingForBranch(?Branch $branch, string $key, mixed $default): mixed
    {
        return app(BranchSettingsResolver::class)
            ->getForOperationalBranch($branch, $key, $default);
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
            'return_fee_mode' => $order->return_fee_mode,
            'return_reason' => $order->return_reason,
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
            'courier' => ($courier = $this->operationalCourier($order)) ? [
                'id' => $courier->id,
                'name' => $courier->name,
                'phone' => $courier->phone,
            ] : null,
        ];
    }

    /**
     * Read old split assignments as one courier without re-exposing their
     * separate roles. New direct orders always use courier_id.
     */
    private function operationalCourier(Order $order): ?User
    {
        return $order->courier ?: $order->deliveryCourier ?: $order->pickupCourier;
    }
}
