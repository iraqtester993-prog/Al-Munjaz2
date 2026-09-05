<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\OrderStatusLog;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\IraqiMobilePhone;
use App\Services\BranchSettingsResolver;
use App\Services\CourierLocationService;
use App\Services\CourierOrderAccess;
use App\Services\CourierOrderAssignmentService;
use App\Services\OrderNumberService;
use App\Services\OrderWorkflowService;
use App\Services\PricingService;
use App\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AppOrderController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'filter' => ['nullable', Rule::in(array_merge(['all'], Order::STATUSES))],
            'q' => ['nullable', 'string', 'max:120'],
            // Cursor pagination keeps the mobile list bounded even for a
            // merchant or courier account with a long delivery history.
            'cursor' => ['nullable', 'string', 'max:1024'],
            'list' => ['nullable', 'boolean'],
            // Home-screen rows link directly to a detail sheet. Keeping the
            // requested id in the Inertia payload makes that work on both a
            // cold load and an in-app visit where the component is retained.
            'open' => ['nullable', 'integer', 'min:1'],
            // `detail` is intentionally served by this same authenticated
            // route as a small JSON response. This avoids a second public
            // route while ensuring the exact same tenant/courier scope is
            // applied before an order sheet is opened.
            'detail' => ['nullable', 'integer', 'min:1'],
            // An archive detail is still fetched through the same authorised
            // endpoint, but it must not leak into normal active queues.
            'archive' => ['nullable', 'boolean'],
            // A courier may inspect an unassigned pending offer before
            // claiming it. The same availability scope is enforced below.
            'pending' => ['nullable', 'boolean'],
        ]);

        $viewer = $request->user();
        // Direct orders have only two participating account types: the
        // merchant and the one courier who receives and delivers the parcel.
        // Legacy specialist accounts remain preserved in user history but no
        // longer receive an operational order queue.
        abort_unless(in_array($viewer->role, ['merchant', 'courier'], true), 403);
        $isCourier = $viewer->role === 'courier';
        $filter = $request->input('filter', 'all');

        $baseQuery = $isCourier
            ? app(CourierOrderAccess::class)->assigned($viewer)
            : Order::query();

        if ($request->filled('detail')) {
            abort_unless($request->expectsJson(), 406);

            $detailQuery = $isCourier && $request->boolean('pending')
                ? app(CourierOrderAccess::class)->available($viewer)
                : clone $baseQuery;
            if ($request->boolean('archive')) {
                $this->scopeArchiveHistory($detailQuery);
            } else {
                $detailQuery->whereNull('archived_at');
            }

            return response()->json([
                'order' => $this->detailPayloadFor(
                    $detailQuery->whereKey($request->integer('detail'))->firstOrFail(),
                    $viewer,
                    $isCourier,
                ),
            ]);
        }

        // A delivered or returned order remains in its status card until it
        // is archived manually or by the nightly archive task. A status
        // change by itself must never make work disappear from the mobile
        // application.
        // A new order is not assigned yet, so it is deliberately absent from
        // the courier's personal history.  It must nevertheless be visible
        // under the courier's “Pending” queue as well as on the home offer
        // screen, otherwise the status card opens an empty list.
        $listBaseQuery = $isCourier && $filter === 'pending'
            ? app(CourierOrderAccess::class)->available($viewer)
            : $baseQuery;

        $query = clone $listBaseQuery;

        if ($request->boolean('archive')) {
            $this->scopeArchiveHistory($query);
        } else {
            $query->whereNull('archived_at');
        }

        $q = $request->input('q');

        if ($filter !== 'all') {
            $query->where('status', $filter);
        }

        if ($q) {
            $query->where(function ($builder) use ($q, $isCourier) {
                $builder->where('track_no', 'like', "%{$q}%")
                    ->orWhere('customer_name_ar', 'like', "%{$q}%")
                    ->orWhere('customer_name_en', 'like', "%{$q}%")
                    // The courier query is already restricted to orders
                    // explicitly assigned to that courier. Phone search is
                    // therefore useful operationally without exposing a
                    // regional or another courier's customer directory.
                    ->orWhere('phone', 'like', "%{$q}%");

                // Search stays inside the already-authorised order query.
                // A courier can locate an assigned delivery by merchant name;
                // a merchant can locate their own order by its courier.
                $relation = $isCourier ? 'merchant' : 'courier';
                $builder->orWhereHas($relation, function (Builder $person) use ($q, $isCourier): void {
                    $person->where('name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%");

                    if ($isCourier) {
                        $person->orWhere('shop_name', 'like', "%{$q}%");
                    }
                });
            });
        }

        // The status overview has no list on screen, so avoid loading even a
        // first page until the user explicitly enters a list or searches.
        $shouldLoadSummaries = $filter !== 'all' || filled($q) || $request->boolean('list');
        $orders = collect();
        $pagination = [
            'next_cursor' => null,
            'has_more' => false,
            // Ten compact cards are enough for a phone viewport. Keeping the
            // first cursor page small avoids a visible pause when a courier
            // has thousands of historical assignments; the client appends
            // the next ten only after the user chooses "Show more".
            'per_page' => 10,
        ];

        if ($shouldLoadSummaries) {
            $paginator = $query
                ->select([
                    'id',
                    'track_no',
                    'customer_name_ar',
                    'customer_name_en',
                    // The courier may contact the customer at every visible
                    // operational state. This is a small scalar in a list
                    // summary, unlike the history and relationship graph
                    // which remain on-demand in the order sheet.
                    'phone',
                    'phone2',
                    'address_ar',
                    'address_en',
                    'order_type',
                    'delivery_vehicle',
                    'vehicle_note',
                    'price',
                    'fee',
                    'status',
                    'pickup_deadline_at',
                    // Cursor pagination must include every ordered column.
                    // Omitting this compact scalar encoded a null created_at
                    // into the next cursor and made page two fail at runtime.
                    'created_at',
                ])
                // The list is a live operational queue: show the most
                // recently created order first.  Keep `id` as a stable
                // tie-breaker for cursor pagination when two orders share a
                // database timestamp.
                ->latest('created_at')
                ->latest('id')
                ->cursorPaginate(10, ['*'], 'cursor', $request->input('cursor'));

            // List cards receive only data needed to paint a card. Expensive
            // relationship graphs and complete operational timelines are now
            // requested only when the user opens that one order.
            $orders = $paginator->getCollection()
                ->map(fn (Order $order) => $this->summaryPayload($order))
                ->values();
            $pagination = [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->nextCursor() !== null,
                'per_page' => 10,
            ];
        }

        // Keep the status cards and the queue they open in exactly the same
        // active scope. Delivered and returned work stays visible until its
        // merchant or courier archives it manually or the nightly task runs.
        $counts = $this->countsFor((clone $baseQuery)->whereNull('archived_at'));

        if ($isCourier) {
            $counts['pending'] = app(CourierOrderAccess::class)
                ->available($viewer)
                ->whereNull('archived_at')
                ->count();
        }

        $payload = [
            'orders' => $orders,
            'pagination' => $pagination,
            'counts' => $counts,
            'filter' => $filter,
            'q' => $q,
            'list' => $request->boolean('list'),
            'openOrderId' => $request->integer('open') ?: null,
            'archive' => $request->boolean('archive'),
            'isCourier' => $isCourier,
            'canAcceptOrders' => ! $isCourier || $viewer->isCourierVerified(),
            // The pending-offer screen needs the live duty state so it can
            // explain why a courier cannot accept a job before sending a
            // claim request that the server will reject.
            'onDuty' => $isCourier && $viewer->isCourierVerified() && (bool) $viewer->is_online,
            'wallet' => $this->walletData($viewer),
        ];

        // The "show more" control performs a same-origin JSON request so it
        // can append the next cursor page without recreating the whole view.
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Mobile/Orders', $payload);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->role === 'merchant', 403, 'إنشاء الطلبات متاح للتاجر فقط.');
        $merchantPickup = $this->merchantPickupSnapshot($user);
        $hasSubmittedPickup = $this->hasSubmittedPickupLocation($request);
        $pickupIsRequired = $hasSubmittedPickup || ! $merchantPickup;

        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            // Customer contact numbers use the same Iraqi mobile format as
            // newly created accounts.  Keep this server-side so a stale PWA
            // or a handcrafted request cannot bypass the form constraint.
            'phone' => ['bail', 'required', 'string', new IraqiMobilePhone],
            'phone2' => ['bail', 'nullable', 'string', new IraqiMobilePhone],
            'address_ar' => ['required', 'string', 'max:255'],
            // A saved shop point is the default, not a lock. A complete
            // per-order location is allowed to replace it for this order;
            // partial coordinates must never silently fall back to the shop.
            'pickup_latitude' => [$pickupIsRequired ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => [$pickupIsRequired ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
            'pickup_location_label' => [$pickupIsRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'delivery_vehicle' => ['required', Rule::in(['normal', 'bike', 'sedan', 'suv', 'truck'])],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Each order keeps an immutable pickup snapshot. If the client does
        // not send a tuple, use the merchant's saved shop point; otherwise
        // preserve the explicit point selected for this one order.
        $data = [...$data, ...$this->resolvedPickupLocation($request, $data, $merchantPickup)];

        $tenant = TenantContext::tenant();

        $provinceId = $this->operatingProvinceForUser($user);
        abort_unless($provinceId, 422, 'هذا الحساب غير مرتبط بمحافظة تشغيل مفعّلة.');
        $operatingBranch = $this->operatingBranchForUser($user, $provinceId);
        // The platform's main merchant accounts can operate before branches
        // are configured. A branch-owned account remains strictly isolated
        // and must still have its assigned active branch.
        if ((int) $user->branch_id > 0 && ! $operatingBranch) {
            throw ValidationException::withMessages([
                'branch' => 'هذا الحساب غير مرتبط بفرع نشط.',
            ]);
        }

        $order = new Order($data);
        $order->tenant_id = $tenant->id;
        $order->source = 'merchant';
        $order->customer_name_en = $request->input('customer_name_en') ?: $data['customer_name_ar'];
        $order->address_en = $request->input('address_en') ?: $data['address_ar'];
        $order->track_no = app(OrderNumberService::class)->next();
        $order->date = $request->input('date') ?: today();
        $order->status = 'pending';
        $order->workflow_stage = 'created';
        $order->province_id = $provinceId;
        // These operational defaults are controlled from the dashboard.  A
        // merchant can never override them in a browser request.
        $availabilityMinutes = max(1, min((int) $this->settingForBranch($operatingBranch, 'order_expiry_minutes', 30), 1440));
        // Cached clients created before the weight field existed do not send
        // it on edit. Calculate the new quote using the exact same effective
        // value that will be persisted below; otherwise a no-op edit could
        // incorrectly quote the job as 0 g.
        $effectiveWeightGrams = array_key_exists('weight_grams', $data)
            ? (int) ($data['weight_grams'] ?? 0)
            : (int) ($order->weight_grams ?? 0);

        $quote = app(PricingService::class)->quote(
            $user,
            $provinceId,
            $data['order_type'] ?? null,
            $data['delivery_vehicle'],
            $effectiveWeightGrams,
            max(0, min((int) $this->settingForBranch($operatingBranch, 'delivery_fee', 0), 1_000_000)),
        );
        $order->weight_grams = (int) ($data['weight_grams'] ?? 0);
        $order->fee = $quote['fee'];
        $order->return_fee = $quote['return_fee'];
        $order->pricing_rule_id = $quote['rule']?->id;
        $offerOpenedAt = now();
        $order->offer_opened_at = $offerOpenedAt;
        $order->pickup_deadline_at = $offerOpenedAt->copy()->addMinutes($availabilityMinutes);
        $order->merchant_id = $user->id;
        $order->created_by = $user->id;
        $order->branch_id = $operatingBranch?->id;
        $order->origin_branch_id = $operatingBranch?->id;
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
            // `exists` deliberately treats a blank input as submitted. A
            // malformed explicit point should receive a validation error,
            // never be replaced invisibly by the saved shop location.
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

            // The validator normally handles this branch. Keep this guard so
            // a future rule change cannot create an order without a pickup.
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

    public function update(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        abort_unless($order->status === 'pending', 422, 'يمكن تعديل الطلبات قيد الانتظار فقط.');

        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            'phone' => ['bail', 'required', 'string', new IraqiMobilePhone],
            'phone2' => ['bail', 'nullable', 'string', new IraqiMobilePhone],
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
        if ((int) $request->user()->branch_id > 0 && ! $operatingBranch) {
            throw ValidationException::withMessages([
                'branch' => 'هذا الحساب غير مرتبط بفرع نشط.',
            ]);
        }

        // Older installed clients did not submit this field when editing an
        // order. Keep its persisted value instead of silently repricing the
        // order as a 0 g shipment (and make the value used for the quote
        // exactly match the value that will be stored below).
        $effectiveWeightGrams = array_key_exists('weight_grams', $data)
            ? (int) ($data['weight_grams'] ?? 0)
            : (int) ($order->weight_grams ?? 0);

        $quote = app(PricingService::class)->quote(
            $request->user(),
            $provinceId,
            $data['order_type'] ?? null,
            $data['delivery_vehicle'],
            $effectiveWeightGrams,
            max(0, min((int) $this->settingForBranch($operatingBranch, 'delivery_fee', 0), 1_000_000)),
        );

        $order->update([
            ...$data,
            'customer_name_en' => $request->input('customer_name_en') ?: $data['customer_name_ar'],
            'address_en' => $request->input('address_en') ?: $data['address_ar'],
            // Older app clients did not submit a weight input on edit. Keep
            // their existing value rather than silently changing it to zero.
            'weight_grams' => $effectiveWeightGrams,
            'fee' => $quote['fee'],
            'return_fee' => $quote['return_fee'],
            'pricing_rule_id' => $quote['rule']?->id,
            'province_id' => $provinceId,
            'branch_id' => $operatingBranch?->id,
            'origin_branch_id' => $operatingBranch?->id,
        ]);

        return back()->with('success', __('orders.updated', ['track' => $order->track_no]));
    }

    /**
     * A merchant may withdraw only an untouched pending order.  The record is
     * soft-deleted so administration retains the complete operational audit
     * and can restore it if the merchant acted by mistake.
     */
    public function destroy(Request $request, Order $order)
    {
        $user = $request->user();

        abort_unless($user?->role === 'merchant', 403, 'حذف الطلب متاح للتاجر فقط.');

        $trackNo = DB::transaction(function () use ($order, $user, $request): string {
            // Route binding is deliberately tenant-neutral for couriers. Lock
            // the source row again inside this transaction before validating
            // its status so a simultaneous courier acceptance cannot race a
            // merchant deletion.
            $locked = Order::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);

            abort_unless(
                (int) $locked->tenant_id === (int) $user->tenant_id
                    && (int) $locked->merchant_id === (int) $user->id,
                403,
                'لا يمكنك حذف طلب لا يخص حسابك.'
            );

            abort_unless(
                $locked->status === 'pending'
                    && ! $locked->courier_id
                    && ! $locked->pickup_courier_id
                    && ! $locked->delivery_courier_id,
                422,
                'يمكن حذف الطلبات قيد الانتظار وغير المعيّنة فقط.'
            );

            // A financial posting means the request is part of the
            // ledger—even if an old record still appears pending—so it must
            // be handled by administration rather than removed by a merchant.
            abort_if(
                Transaction::withoutGlobalScopes()->where('order_id', $locked->id)->exists(),
                422,
                'لا يمكن حذف طلب مرتبط بحركة مالية. تواصل مع الدعم.'
            );

            $locked->delete();

            ActivityLog::create([
                'tenant_id' => $locked->tenant_id,
                'user_id' => $user->id,
                'action' => 'order.soft_deleted_by_merchant',
                'subject_type' => 'order',
                'subject_id' => $locked->id,
                'data' => ['track_no' => $locked->track_no, 'status' => $locked->status],
                'ip' => $request->ip(),
            ]);

            return $locked->track_no;
        });

        return back()->with('success', 'تم حذف الطلب '.$trackNo.'. يمكن للإدارة استعادته عند الحاجة.');
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
            app(CourierLocationService::class)->requireFreshOperationalLocation($user);

            $allowed = match ($order->status) {
                'approved' => ['courier'],
                // Returns are deliberately handled by the two-step endpoint
                // below so a fee and physical handback cannot be skipped.
                'courier' => ['delivered'],
                default => [],
            };

            if (! in_array($to, $allowed, true)) {
                // A courier can legitimately tap an action after another
                // request has already advanced the order. Keep this as a
                // normal Inertia form error so the installed app can show the
                // reason instead of receiving an opaque 422 error page.
                throw ValidationException::withMessages([
                    'order' => ['انتقال حالة الطلب غير مسموح.'],
                ]);
            }
        }

        app(OrderWorkflowService::class)->changeStatus($order, $to, $user, $request->input('note'));

        return back()->with('success', __('orders.status_changed'));
    }

    public function startReturn(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        $courier = $request->user();
        abort_unless($courier->role === 'courier', 403, 'إرجاع الطلب متاح للمندوب المعيّن فقط.');
        app(CourierLocationService::class)->requireFreshOperationalLocation($courier);

        $data = $request->validate([
            'fee_mode' => ['required', Rule::in(['none', 'fee'])],
            'return_reason' => ['required', 'string', 'max:255'],
        ]);

        app(OrderWorkflowService::class)->startCourierReturn(
            $order,
            $courier,
            $data['fee_mode'],
            $data['return_reason'],
        );

        return back()->with('success', 'تم تسجيل الإرجاع. أكّد تسليم الطلب إلى التاجر بعد إتمامه فعلياً.');
    }

    public function confirmReturnToMerchant(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        $courier = $request->user();
        abort_unless($courier->role === 'courier', 403, 'تأكيد الإرجاع متاح للمندوب المعيّن فقط.');
        app(CourierLocationService::class)->requireFreshOperationalLocation($courier);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        app(OrderWorkflowService::class)->confirmCourierReturnToMerchant(
            $order,
            $courier,
            $data['note'] ?? null,
        );

        return back()->with('success', 'تم تأكيد إعادة الطلب إلى التاجر وتحديث السجل التشغيلي والمالي.');
    }

    /**
     * This endpoint is retained for clients on an older installed release.
     * Returned orders are final from the merchant's perspective and may not
     * be offered to couriers again.
     */
    public function recreate(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        abort_unless($request->user()->role === 'merchant', 403, 'إعادة إنشاء الطلب متاحة للتاجر فقط.');
        abort(422, 'لا يمكن للتاجر إعادة نشر الطلب الراجع.');
    }

    /** Re-open an unclaimed pending order for a fresh courier offer window. */
    public function republish(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        abort_unless($request->user()->role === 'merchant', 403, 'إعادة نشر الطلب متاحة للتاجر فقط.');
        abort_unless($order->status === 'pending' && ! $order->courier_id, 422, 'يمكن إعادة نشر الطلبات قيد الانتظار وغير المقبولة فقط.');
        abort_unless(
            $order->pickup_deadline_at && $order->pickup_deadline_at->isPast(),
            422,
            'لا يمكن إعادة نشر الطلب قبل انتهاء وقت عرضه للمندوبين.'
        );

        $minutes = max(1, min((int) $this->settingForOrder($order, 'order_expiry_minutes', 30), 1440));
        $offerOpenedAt = now();
        $order->forceFill([
            'offer_opened_at' => $offerOpenedAt,
            'pickup_deadline_at' => $offerOpenedAt->copy()->addMinutes($minutes),
        ])->save();

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

        return back()->with('success', 'تم قبول الطلب: حُجز سعر المنتج من رصيد الميزانية واستُقطع مبلغ الإدارة من رصيد Qi.');
    }

    public function archive(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request);
        $actor = $request->user();

        abort_unless(
            in_array($actor->role, ['merchant', 'courier'], true),
            403,
            'أرشفة الطلب متاحة للتاجر أو المندوب فقط.'
        );
        if ($actor->role === 'merchant') {
            // Merchant order lists are tenant-wide for company visibility,
            // but a manual archive is an irreversible operational action.
            // Limit it to the merchant who created that individual order.
            abort_unless($this->canMerchantArchive($order, $actor), 403);
        }
        abort_unless(
            in_array($order->status, Order::ARCHIVABLE_STATUSES, true),
            422,
            'يمكن أرشفة الطلب في حالتي تم التسليم أو راجع فقط.'
        );

        if ($order->archived_at) {
            return back()->with('success', 'هذا الطلب موجود بالفعل في الأرشيف.');
        }

        $order->forceFill(['archived_at' => now()])->save();

        ActivityLog::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $actor->id,
            'action' => $actor->role === 'courier'
                ? 'order.archived_by_courier'
                : 'order.archived_by_merchant',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'data' => ['track_no' => $order->track_no, 'status' => $order->status],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'تم نقل الطلب إلى الأرشيف.');
    }

    /**
     * The archive contains only final orders that were archived manually or
     * by the nightly task. It never becomes a second active-order queue.
     */
    private function scopeArchiveHistory(Builder $orders): Builder
    {
        return $orders
            ->whereIn('status', Order::ARCHIVABLE_STATUSES)
            ->whereNotNull('archived_at');
    }

    protected function authorizeOrder(Order $order, Request $request): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        if ($request->user()->role === 'courier') {
            abort_unless(
                app(CourierOrderAccess::class)
                    ->assigned($request->user())
                    ->whereKey($order->id)
                    ->exists(),
                403,
            );

            return;
        }

        if ($request->user()->role === 'merchant') {
            abort_unless($order->tenant_id === $request->user()->tenant_id, 403);

            return;
        }

        abort(403);
    }

    /**
     * A merchant may archive only their own final order. Older imported
     * records can lack `merchant_id`, so `created_by` is a safe legacy
     * fallback only when that primary owner column is null.
     */
    private function canMerchantArchive(Order $order, User $merchant): bool
    {
        $ownerId = $order->merchant_id ?? $order->created_by;

        return $merchant->role === 'merchant'
            && (int) $order->tenant_id === (int) $merchant->tenant_id
            && (int) $ownerId === (int) $merchant->id;
    }

    /**
     * The list endpoint intentionally contains no relationship graph. Load
     * the complete data only for an explicitly opened order, after the same
     * query scope used for the list has authorised it.
     */
    private function detailPayloadFor(Order $order, User $viewer, bool $isCourier): array
    {
        $order->load([
            'courier:id,name,phone,vehicle,role',
            'pickupCourier:id,name,phone,vehicle,role',
            'deliveryCourier:id,name,phone,vehicle,role',
            'merchant:id,name,phone,address,shop_name,merchant_verified_at,role',
            'tenant:id,name',
            // Route branches can belong to the platform operations tenant,
            // so the relations intentionally resolve outside the viewer's
            // tenant scope. The order itself was already authorised above.
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
        ]);

        // Order movements retain the branch ids that were active when an
        // event occurred. Resolve that historical route independently of the
        // current viewer's tenant so the timeline remains truthful after a
        // cross-network assignment.
        $movementBranchIds = $order->movements
            ->flatMap(fn (OrderMovement $movement) => [
                $movement->from_branch_id,
                $movement->to_branch_id,
            ])
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

        // This is deliberately a single existence query for one opened order
        // rather than a transaction lookup for every card in every page.
        $hasFinancialPosting = Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->whereNull('deleted_at')
            ->exists();

        return $this->detailPayload(
            $order,
            $movementBranches,
            $hasFinancialPosting,
            $viewer,
            $isCourier,
        );
    }

    /**
     * Small, card-only representation used by cursor-paginated mobile lists.
     * Keep this independent from the sheet payload so list requests never
     * serialise customer contact information, users, branches or histories.
     */
    private function summaryPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'track_no' => $order->track_no,
            'customer_name_ar' => $order->customer_name_ar,
            'customer_name_en' => $order->customer_name_en,
            'phone' => $order->phone,
            // Retain the alternate delivery contact in the compact payload
            // too, so the detail sheet always shows it while its full
            // on-demand request is still loading.
            'phone2' => $order->phone2,
            'phone_revealed' => true,
            'address_ar' => $order->address_ar,
            'address_en' => $order->address_en,
            'order_type' => $order->order_type,
            'delivery_vehicle' => $order->delivery_vehicle,
            'vehicle_note' => $order->vehicle_note,
            'price' => $order->price,
            // Keeping the persisted fee in the summary preserves the
            // existing Inertia prop contract for older installed clients.
            'fee' => $order->fee,
            'status' => $order->status,
            // The client keeps this value so a restored/appended response
            // can retain the same newest-first order without guessing from
            // the tracking number.
            'created_at' => $order->created_at?->toIso8601String(),
            'pickup_deadline_at' => $order->pickup_deadline_at?->toIso8601String(),
        ];
    }

    /**
     * Full order payload for the on-demand sheet only.
     */
    private function detailPayload(
        Order $order,
        $movementBranches,
        bool $hasFinancialPosting,
        User $viewer,
        bool $isCourier,
    ): array {
        return [
            'id' => $order->id,
            'track_no' => $order->track_no,
            'source' => $order->source,
            'customer_name_ar' => $order->customer_name_ar,
            'customer_name_en' => $order->customer_name_en,
            // The merchant and assigned courier need customer contact details
            // at every delivery state, including pending.
            'phone' => $order->phone,
            'phone2' => $order->phone2,
            'phone_revealed' => true,
            'address_ar' => $order->address_ar,
            'address_en' => $order->address_en,
            'pickup_latitude' => $order->pickup_latitude === null ? null : (float) $order->pickup_latitude,
            'pickup_longitude' => $order->pickup_longitude === null ? null : (float) $order->pickup_longitude,
            'pickup_location_label' => $order->pickup_location_label,
            'order_type' => $order->order_type,
            'delivery_vehicle' => $order->delivery_vehicle,
            'weight_grams' => (int) ($order->weight_grams ?? 0),
            'vehicle_note' => $order->vehicle_note,
            'province_id' => $order->province_id,
            'price' => $order->price,
            'fee' => $order->fee,
            // The pricing quote stays immutable; the return flow exposes the
            // separately selected amount only after a courier chooses it.
            'return_fee' => (int) ($order->return_fee ?? 0),
            'return_fee_applied' => (int) ($order->return_fee_applied ?? 0),
            'return_fee_mode' => $order->return_fee_mode,
            'return_reason' => $order->return_reason,
            'pricing_rule_id' => $order->pricing_rule_id,
            'status' => $order->status,
            'workflow_stage' => $order->workflow_stage,
            'date' => $order->date?->toDateString(),
            'created_at' => $this->timestamp($order->created_at),
            'updated_at' => $this->timestamp($order->updated_at),
            'returned_at' => $this->timestamp($order->returned_at),
            'returned_to_merchant_at' => $this->timestamp($order->returned_to_merchant_at),
            'return_fee_charged_at' => $this->timestamp($order->return_fee_charged_at),
            'archived_at' => $this->timestamp($order->archived_at),
            'notes' => $order->notes,
            'pickup_deadline_at' => $order->pickup_deadline_at?->toIso8601String(),
            // `courier` is the sole operational assignment. `assigned_courier`
            // retains a legacy fallback only when reading an old record.
            'courier' => $this->assignedCourierPayload($order),
            'assigned_courier' => $this->assignedCourierPayload($order),
            'merchant' => $order->merchant
                ? [
                    'name' => $order->merchant->name,
                    'shop_name' => $order->merchant->shop_name,
                    'phone' => $order->merchant->phone,
                    'address' => $order->merchant->address,
                    'verified' => $order->merchant->isMerchantVerified(),
                ]
                : ($order->tenant ? ['name' => $order->tenant->name, 'phone' => null, 'address' => null] : null),
            'courier_id' => $order->courier_id,
            'merchant_id' => $order->merchant_id,
            'can_delete_by_merchant' => ! $isCourier
                && $viewer->role === 'merchant'
                && (int) $order->merchant_id === (int) $viewer->id
                && $order->status === 'pending'
                && ! $order->courier_id
                && ! $order->pickup_courier_id
                && ! $order->delivery_courier_id
                && ! $hasFinancialPosting,
            'can_archive' => ($viewer->role === 'courier'
                    || $this->canMerchantArchive($order, $viewer))
                && in_array($order->status, Order::ARCHIVABLE_STATUSES, true)
                && $order->archived_at === null,
            'origin_branch' => $order->originBranch
                ? $this->branchPayload($order->originBranch)
                : null,
            'destination_branch' => $order->destinationBranch
                ? $this->branchPayload($order->destinationBranch)
                : null,
            'timeline' => $this->timelinePayload($order, $movementBranches),
        ];
    }

    private function operatingBranchForUser(User $user, int $provinceId): ?Branch
    {
        $branchId = (int) $user->branch_id;

        $branches = Branch::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            // withoutGlobalScopes() also removes SoftDeletes, so deleted
            // branches must never become an operational fallback.
            ->whereNull('deleted_at')
            ->where('province_id', $provinceId)
            ->whereHas('province', fn ($province) => $province->platform()->active());

        if ($branchId > 0) {
            return $branches->whereKey($branchId)->first();
        }

        // Legacy accounts created before branch selection was introduced may
        // not have a branch assignment. The first active branch for their
        // authorised province is a safe one-time compatibility fallback.
        return $branches->orderBy('name_ar')->first();
    }

    private function settingForBranch(?Branch $branch, string $key, mixed $default): mixed
    {
        return app(BranchSettingsResolver::class)
            ->getForOperationalBranch($branch, $key, $default);
    }

    private function settingForOrder(Order $order, string $key, mixed $default): mixed
    {
        $branchId = (int) ($order->branch_id ?: $order->origin_branch_id);

        return app(BranchSettingsResolver::class)
            ->getForOperationalBranch($branchId > 0 ? $branchId : null, $key, $default);
    }

    private function operatingProvinceForUser(User $user): ?int
    {
        $provinceId = $user->provinces()->active()->value('provinces.id');

        if ($provinceId) {
            return (int) $provinceId;
        }

        return $user->branch_id
            ? Branch::withoutGlobalScopes()
                ->whereKey($user->branch_id)
                ->whereNull('deleted_at')
                ->value('province_id')
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

    /**
     * The application has one courier card per order. `courier_id` is the
     * operational source of truth; fallback relations are only for historical
     * records created before the one-courier model.
     */
    private function assignedCourierPayload(Order $order): ?array
    {
        $courier = $order->courier ?: $order->deliveryCourier ?: $order->pickupCourier;

        return $courier ? [
            'id' => $courier->id,
            'name' => $courier->name,
            'phone' => $courier->phone,
            'vehicle' => $courier->vehicle,
            'role' => $courier->role,
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

    /**
     * Read every visible order status in one aggregate query. The previous
     * version ran one count query per status on every mobile page visit.
     */
    protected function countsFor(Builder $query): array
    {
        $aggregate = (clone $query)->selectRaw('COUNT(*) as aggregate_all');

        foreach (Order::STATUSES as $status) {
            $aggregate->selectRaw(
                "SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as aggregate_{$status}",
                [$status],
            );
        }

        $values = $aggregate->first();

        return [
            'all' => (int) ($values->aggregate_all ?? 0),
            ...collect(Order::STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => (int) ($values->{"aggregate_{$status}"} ?? 0)])
                ->all(),
        ];
    }

    protected function walletData(User $user): array
    {
        $wallet = $user->wallet;

        return [
            'balance' => $wallet?->balance ?? 0,
            'budget' => $wallet?->budget ?? 0,
            'budget_balance' => $wallet?->budget_balance ?? 0,
        ];
    }
}
