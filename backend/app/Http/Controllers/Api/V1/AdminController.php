<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $query = User::query()->with(['wallet', 'provinces'])->latest('id');
        if ($role = $request->string('role')->toString()) $query->where('role', $role);
        if ($search = $request->string('search')->toString()) $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
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
        return response()->json(['data' => User::query()->with('provinces')->where('role', 'courier')->where('status', 'active')->orderBy('name')->get()->map(fn (User $user) => $this->userData($user))]);
    }

    public function assignCourier(Request $request, Order $order): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['courier_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'courier')->where('status', 'active'))]]);
        $courier = User::findOrFail($data['courier_id']);
        abort_unless($courier->provinces()->whereKey($order->province_id)->exists(), 422, 'المندوب لا يعمل في محافظة هذا الطلب.');
        DB::transaction(function () use ($order, $data, $request) {
            $from = $order->status;
            $order->update(['courier_id' => $data['courier_id'], 'status' => 'courier', 'picked_at' => now()]);
            OrderStatusLog::create(['tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'from_status' => $from, 'to_status' => 'courier', 'user_id' => $request->user()->id, 'note' => 'تم تعيين مندوب']);
        });
        return response()->json(['data' => ['id' => $order->id, 'courier_id' => $order->courier_id, 'status' => $order->status]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        if ($request->isMethod('get')) {
            return response()->json(['data' => ['currency' => Setting::get('currency', 'IQD'), 'delivery_fee' => Setting::get('delivery_fee', 0), 'support_phone' => Setting::get('support_phone', '')]]);
        }
        $data = $request->validate(['currency' => ['sometimes', 'string', 'max:10'], 'delivery_fee' => ['sometimes', 'integer', 'min:0'], 'support_phone' => ['sometimes', 'nullable', 'string', 'max:30']]);
        foreach ($data as $key => $value) Setting::set($key, $value);
        return response()->json(['data' => $data]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'support'], true), 403);
    }

    private function userData(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'phone' => $user->phone, 'role' => $user->role, 'status' => $user->status, 'vehicle' => $user->vehicle, 'is_online' => $user->is_online, 'provinces' => $user->provinces->map(fn ($province) => ['id' => $province->id, 'name' => $province->name_ar])->values(), 'wallet' => ['balance' => $user->wallet?->balance ?? 0, 'budget' => $user->wallet?->budget ?? 0]];
    }
}
