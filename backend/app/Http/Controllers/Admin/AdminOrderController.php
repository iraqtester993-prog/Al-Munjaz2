<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderMovement;
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
            'courier:id,name,phone',
            'tenant:id,name',
            'originBranch:id,name_ar,city',
            'destinationBranch:id,name_ar,city',
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
            'tenant_id' => $o->tenant_id,
            'tenant' => $o->tenant?->name,
            'province_id' => $o->province_id,
            'origin_branch_id' => $o->origin_branch_id,
            'destination_branch_id' => $o->destination_branch_id,
            'origin_branch' => $o->originBranch ? ['id' => $o->originBranch->id, 'name' => $o->originBranch->name_ar, 'city' => $o->originBranch->city] : null,
            'destination_branch' => $o->destinationBranch ? ['id' => $o->destinationBranch->id, 'name' => $o->destinationBranch->name_ar, 'city' => $o->destinationBranch->city] : null,
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

        $branches = Branch::withoutGlobalScopes()
            ->where('is_active', true)
            ->orderBy('name_ar')
            ->get(['id', 'tenant_id', 'code', 'name_ar', 'city'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'tenant_id' => $branch->tenant_id,
                'code' => $branch->code,
                'name' => $branch->name_ar,
                'city' => $branch->city,
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

        $branches = Branch::withoutGlobalScopes()->whereIn('id', $branchIds)->get();
        abort_unless(
            $branches->count() === $branchIds->count() && $branches->every(fn (Branch $branch) => (int) $branch->tenant_id === (int) $order->tenant_id),
            422,
            'يجب أن تتبع الفروع المختارة لنفس حساب التاجر.'
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

}
