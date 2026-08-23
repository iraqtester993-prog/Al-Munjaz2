<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
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

        $query = $isCourier
            ? Order::query()->where('courier_id', $request->user()->id)
            : Order::query();

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

        $orders = $query->with('courier:id,name,phone')->latest('id')->get()->map(fn (Order $o) => [
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
            'price' => $o->price,
            'fee' => $o->fee,
            'status' => $o->status,
            'date' => $o->date->toDateString(),
            'notes' => $o->notes,
            'courier' => $o->courier ? ['name' => $o->courier->name, 'phone' => $o->courier->phone] : null,
            'courier_id' => $o->courier_id,
        ]);

        $counts = $isCourier
            ? ['all' => $orders->count(), 'pending' => 0, 'approved' => 0, 'courier' => 0, 'delivered' => 0, 'returned' => 0]
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
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = TenantContext::tenant();
        $user = $request->user();

        $order = new Order($data);
        $order->tenant_id = $tenant->id;
        $order->source = 'merchant';
        $order->customer_name_en = $request->input('customer_name_en') ?: $data['customer_name_ar'];
        $order->address_en = $request->input('address_en') ?: $data['address_ar'];
        $order->track_no = 'ALM-'.mt_rand(100000, 999999);
        $order->date = $request->input('date') ?: today();
        $order->status = 'pending';
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

        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'phone2' => ['nullable', 'string', 'max:30'],
            'address_ar' => ['required', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

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

        $from = $order->status;
        $to = $request->input('status');
        $user = $request->user();

        $this->applyStatus($order, $to, $user, $request->input('note'));

        return back()->with('success', __('orders.status_changed'));
    }

    protected function applyStatus(Order $order, string $to, User $user, ?string $note = null): void
    {
        $from = $order->status;
        if ($from === $to) {
            return;
        }

        $order->status = $to;

        if ($to === 'approved') {
            $order->accepted_at = now();
        }
        if ($to === 'courier') {
            $order->picked_at = now();
            $order->courier_id ??= $user->id;
        }
        if ($to === 'delivered') {
            $order->delivered_at = now();
        }
        if ($to === 'returned') {
            $order->returned_at = now();
        }

        $order->save();

        OrderStatusLog::create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user->id,
            'note' => $note,
        ]);

        ActivityLog::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $user->id,
            'action' => 'order.status',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'data' => ['from' => $from, 'to' => $to],
            'ip' => request()->ip(),
        ]);

        $labels = [
            'pending' => ['الطلب بانتظار الموافقة', 'Order awaiting approval'],
            'approved' => ['تم قبول الطلب', 'Order approved'],
            'courier' => ['الطلب مع المندوب', 'Order with courier'],
            'delivered' => ['تم تسليم الطلب', 'Order delivered'],
            'returned' => ['تم إرجاع الطلب', 'Order returned'],
        ];

        [$bodyAr, $bodyEn] = $labels[$to] ?? ['', ''];

        Notification::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => null,
            'type' => 'order',
            'title_ar' => $order->track_no,
            'title_en' => $order->track_no,
            'title_ku' => $order->track_no,
            'body_ar' => $bodyAr,
            'body_en' => $bodyEn,
            'body_ku' => $bodyAr,
            'data' => ['order_id' => $order->id, 'status' => $to],
        ]);
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
