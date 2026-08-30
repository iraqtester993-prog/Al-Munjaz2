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
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CourierLocationService;
use App\Services\CourierOrderAccess;
use App\Services\CourierOrderAssignmentService;
use App\Services\OrderWorkflowService;
use App\Services\PricingService;
use App\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
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
            // Cursor pagination keeps the mobile list bounded even for a
            // merchant or courier account with a long delivery history.
            'cursor' => ['nullable', 'string', 'max:1024'],
            'list' => ['nullable', 'boolean'],
            // `detail` is intentionally served by this same authenticated
            // route as a small JSON response. This avoids a second public
            // route while ensuring the exact same tenant/courier scope is
            // applied before an order sheet is opened.
            'detail' => ['nullable', 'integer', 'min:1'],
        ]);

        $viewer = $request->user();
        $isCourier = $viewer->isCourierRole();

        $baseQuery = $isCourier
            ? app(CourierOrderAccess::class)->assigned($viewer)
            : Order::query();

        if ($request->filled('detail')) {
            abort_unless($request->expectsJson(), 406);

            return response()->json([
                'order' => $this->detailPayloadFor(
                    (clone $baseQuery)->whereKey($request->integer('detail'))->firstOrFail(),
                    $viewer,
                    $isCourier,
                ),
            ]);
        }

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

        // The status overview has no list on screen, so avoid loading even a
        // first page until the user explicitly enters a list or searches.
        $shouldLoadSummaries = $filter !== 'all' || filled($q) || $request->boolean('list');
        $orders = collect();
        $pagination = [
            'next_cursor' => null,
            'has_more' => false,
            'per_page' => 20,
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
                    'address_ar',
                    'address_en',
                    'order_type',
                    'delivery_vehicle',
                    'vehicle_note',
                    'price',
                    'fee',
                    'status',
                    'pickup_deadline_at',
                ])
                ->latest('id')
                ->cursorPaginate(20, ['*'], 'cursor', $request->input('cursor'));

            // List cards receive only data needed to paint a card. Expensive
            // relationship graphs and complete operational timelines are now
            // requested only when the user opens that one order.
            $orders = $paginator->getCollection()
                ->map(fn (Order $order) => $this->summaryPayload($order))
                ->values();
            $pagination = [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->nextCursor() !== null,
                'per_page' => 20,
            ];
        }

        $counts = $this->countsFor($baseQuery);

        $payload = [
            'orders' => $orders,
            'pagination' => $pagination,
            'counts' => $counts,
            'filter' => $filter,
            'q' => $q,
            'list' => $request->boolean('list'),
            'isCourier' => $isCourier,
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
            max(0, min((int) Setting::get('delivery_fee', 0), 1_000_000)),
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
            'branch_id' => $operatingBranch->id,
            'origin_branch_id' => $operatingBranch->id,
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

        if ($user->isCourierRole()) {
            app(CourierLocationService::class)->requireFreshOperationalLocation($user);

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
        $courier = $request->user();
        abort_unless($courier->role === 'courier', 403, 'إرجاع الطلب متاح للمندوب المعيّن فقط.');
        app(CourierLocationService::class)->requireFreshOperationalLocation($courier);

        $data = $request->validate([
            'fee_mode' => ['required', Rule::in(['none', 'fee'])],
            'return_fee_applied' => ['nullable', 'integer', 'min:1', 'max:1000000', Rule::requiredIf($request->input('fee_mode') === 'fee')],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        app(OrderWorkflowService::class)->startCourierReturn(
            $order,
            $courier,
            $data['fee_mode'] === 'fee' ? (int) $data['return_fee_applied'] : 0,
            $data['note'] ?? null,
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
            'pricing_rule_id' => $order->pricing_rule_id,
            'status' => $order->status,
            'workflow_stage' => $order->workflow_stage,
            'date' => $order->date?->toDateString(),
            'created_at' => $this->timestamp($order->created_at),
            'updated_at' => $this->timestamp($order->updated_at),
            'returned_at' => $this->timestamp($order->returned_at),
            'returned_to_merchant_at' => $this->timestamp($order->returned_to_merchant_at),
            'return_fee_charged_at' => $this->timestamp($order->return_fee_charged_at),
            'notes' => $order->notes,
            'pickup_deadline_at' => $order->pickup_deadline_at?->toIso8601String(),
            // `courier` remains the primary assignment for compatibility.
            // Merchant-facing UI consumes `assigned_courier`, which resolves
            // the proper pickup/delivery assignee for specialist workflows.
            'courier' => $order->courier
                ? ['name' => $order->courier->name, 'phone' => $order->courier->phone, 'vehicle' => $order->courier->vehicle]
                : null,
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
            'pickup_courier_id' => $order->pickup_courier_id,
            'delivery_courier_id' => $order->delivery_courier_id,
            'merchant_id' => $order->merchant_id,
            'can_delete_by_merchant' => ! $isCourier
                && $viewer->role === 'merchant'
                && (int) $order->merchant_id === (int) $viewer->id
                && $order->status === 'pending'
                && ! $order->courier_id
                && ! $order->pickup_courier_id
                && ! $order->delivery_courier_id
                && ! $hasFinancialPosting,
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

    private function operatingProvinceForUser(User $user): ?int
    {
        $provinceId = $user->provinces()->active()->value('provinces.id');

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

    /**
     * The application has one counterparty card per order.  A direct
     * courier assignment, a pickup assignment, and a delivery assignment
     * are all valid operational models; resolving that choice server-side
     * keeps a merchant from seeing their own profile as the courier.
     */
    private function assignedCourierPayload(Order $order): ?array
    {
        $courier = match ($order->status) {
            'approved' => $order->pickupCourier ?: $order->courier ?: $order->deliveryCourier,
            'courier' => $order->deliveryCourier ?: $order->courier ?: $order->pickupCourier,
            default => $order->courier ?: $order->pickupCourier ?: $order->deliveryCourier,
        };

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
        ];
    }
}
