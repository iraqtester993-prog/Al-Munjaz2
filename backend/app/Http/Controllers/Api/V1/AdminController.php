<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderOperationalAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'role' => ['nullable', Rule::in(array_merge(['all'], User::ROLES))],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = User::query()->with(['wallet', 'provinces'])->latest('id');
        if (($role = $data['role'] ?? null) && $role !== 'all') {
            $query->where('role', $role);
        }
        if ($search = $data['search'] ?? null) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }

        return response()->json(['data' => $query->paginate(min($request->integer('per_page', 50), 100))->through(fn (User $user) => $this->userData($user))]);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['status' => ['sometimes', Rule::in(User::STATUSES)], 'is_online' => ['sometimes', 'boolean'], 'vehicle' => ['sometimes', 'nullable', 'string', 'max:60']]);
        $user->update($data);

        return response()->json(['data' => $this->userData($user->fresh('wallet'))]);
    }

    public function couriers(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'role' => ['nullable', Rule::in(array_merge(['all'], User::COURIER_ROLES))],
        ]);

        $query = User::query()
            ->with('provinces')
            ->whereIn('role', User::COURIER_ROLES)
            ->where('status', 'active')
            ->orderBy('name');

        if (($role = $data['role'] ?? null) && $role !== 'all') {
            $query->where('role', $role);
        }

        return response()->json(['data' => $query->get()->map(fn (User $user) => $this->userData($user))]);
    }

    public function assignCourier(Request $request, Order $order): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Platform administrators operate across merchant tenants.  Resolve
        // the order explicitly rather than letting a request tenant scope
        // turn a legitimate network assignment into a misleading 404.
        $order = Order::withoutGlobalScope(TenantScope::class)->findOrFail($order->id);

        $data = $request->validate([
            'courier_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query
                ->whereIn('role', User::DIRECT_ORDER_COURIER_ROLES)
                ->where('status', 'active'))],
            'assignment_role' => ['nullable', Rule::in(OrderOperationalAssignmentService::ASSIGNMENT_ROLES)],
        ]);
        $courier = User::findOrFail($data['courier_id']);
        app(OrderOperationalAssignmentService::class)->assign(
            $order,
            $courier,
            $request->user(),
            $data['assignment_role'] ?? null,
            'تم تعيين المندوب من واجهة API للإدارة.',
        );

        $order->refresh();

        return response()->json(['data' => [
            'id' => $order->id,
            'courier_id' => $order->courier_id,
            'pickup_courier_id' => $order->pickup_courier_id,
            'delivery_courier_id' => $order->delivery_courier_id,
            'status' => $order->status,
            'workflow_stage' => $order->workflow_stage,
        ]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        if ($request->isMethod('get')) {
            return response()->json(['data' => ['currency' => Setting::get('currency', 'IQD'), 'delivery_fee' => Setting::get('delivery_fee', 0), 'support_phone' => Setting::get('support_phone', '')]]);
        }
        $data = $request->validate(['currency' => ['sometimes', 'string', 'max:10'], 'delivery_fee' => ['sometimes', 'integer', 'min:0'], 'support_phone' => ['sometimes', 'nullable', 'string', 'max:30']]);
        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        return response()->json(['data' => $data]);
    }

    private function authorizeAdmin(Request $request): void
    {
        // API mutations expose operational and financial data. Support users
        // may be granted read-only dashboard access later through an explicit
        // policy, but they must never inherit an administrator API surface.
        abort_unless(
            $request->user()?->role === 'admin' && $request->user()->isActiveUser(),
            403,
        );
    }

    private function userData(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'phone' => $user->phone, 'role' => $user->role, 'status' => $user->status, 'vehicle' => $user->vehicle, 'is_online' => $user->is_online, 'assignment_roles' => app(OrderOperationalAssignmentService::class)->modesFor($user), 'provinces' => $user->provinces->map(fn ($province) => ['id' => $province->id, 'name' => $province->name_ar])->values(), 'wallet' => ['balance' => $user->wallet?->balance ?? 0, 'budget' => $user->wallet?->budget ?? 0]];
    }
}
