<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\App\AppOrderController;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
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

        $query = Order::query()->with('courier:id,name,phone', 'tenant:id,name');

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

        $orders = $query->latest('id')->paginate(25)->through(fn (Order $o) => [
            'id' => $o->id,
            'track_no' => $o->track_no,
            'customer_name_ar' => $o->customer_name_ar,
            'phone' => $o->phone,
            'address_ar' => $o->address_ar,
            'price' => $o->price,
            'fee' => $o->fee,
            'status' => $o->status,
            'date' => $o->date->toDateString(),
            'source' => $o->source,
            'tenant' => $o->tenant?->name,
            'courier' => $o->courier ? ['id' => $o->courier->id, 'name' => $o->courier->name, 'phone' => $o->courier->phone] : null,
        ]);

        $counts = [
            'all' => Order::query()->count(),
            'pending' => Order::query()->where('status', 'pending')->count(),
            'approved' => Order::query()->where('status', 'approved')->count(),
            'courier' => Order::query()->where('status', 'courier')->count(),
            'delivered' => Order::query()->where('status', 'delivered')->count(),
            'returned' => Order::query()->where('status', 'returned')->count(),
        ];

        $couriers = User::query()->where('role', 'courier')->where('status', 'active')->get(['id', 'name', 'phone']);

        return Inertia::render('Admin/Orders', [
            'orders' => $orders,
            'counts' => $counts,
            'filter' => $request->input('filter', 'all'),
            'q' => $request->input('q', ''),
            'couriers' => $couriers,
        ]);
    }

    public function status(Request $request, Order $order)
    {
        $request->validate(['status' => ['required', Rule::in(Order::STATUSES)]]);

        $controller = app(AppOrderController::class);

        $this->reflectApply($controller, $order, $request);

        return back()->with('success', __('orders.status_changed'));
    }

    public function assignCourier(Request $request, Order $order)
    {
        $request->validate([
            'courier_id' => ['required', 'exists:users,id'],
        ]);

        $order->update([
            'courier_id' => $request->input('courier_id'),
            'status' => 'approved',
            'accepted_at' => now(),
        ]);

        return back()->with('success', __('orders.courier_assigned'));
    }

    protected function reflectApply(AppOrderController $controller, Order $order, Request $request): void
    {
        $method = new \ReflectionMethod(AppOrderController::class, 'applyStatus');
        $method->setAccessible(true);
        $method->invoke($controller, $order, $request->input('status'), $request->user(), $request->input('note'));
    }
}
