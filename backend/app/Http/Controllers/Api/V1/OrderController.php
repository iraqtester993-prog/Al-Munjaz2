<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Order::query()->with('courier:id,name,phone')->latest('id');

        if ($user->role === 'merchant') $query->where('tenant_id', $user->tenant_id);
        if ($user->role === 'courier') $query->where('courier_id', $user->id);
        if ($status = $request->string('status')->toString()) $query->where('status', $status);

        return response()->json($query->paginate(min($request->integer('per_page', 20), 100))->through(fn (Order $order) => $this->orderData($order)));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        return response()->json(['data' => $this->orderData($order->load('courier:id,name,phone', 'statusLogs'))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'merchant', 403);
        $data = $request->validate([
            'customer_name_ar' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'address_ar' => ['required', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'order_type' => ['nullable', 'string', 'max:60'],
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
        ]);

        $user = $request->user();
        $order = DB::transaction(function () use ($data, $user) {
            $order = Order::create($data + [
                'tenant_id' => $user->tenant_id,
                'created_by' => $user->id,
                'track_no' => 'IQ-'.now()->format('ymd').'-'.str_pad((string) (Order::max('id') + 1), 5, '0', STR_PAD_LEFT),
                'source' => 'merchant', 'date' => today(), 'status' => 'pending',
            ]);
            OrderStatusLog::create(['tenant_id' => $user->tenant_id, 'order_id' => $order->id, 'to_status' => 'pending', 'user_id' => $user->id]);
            return $order;
        });

        return response()->json(['data' => $this->orderData($order)], 201);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate(['status' => ['required', Rule::in(Order::STATUSES)], 'note' => ['nullable', 'string', 'max:255']]);
        $user = $request->user();
        $allowed = $user->isAdmin() || ($user->role === 'courier' && $order->courier_id === $user->id);
        abort_unless($allowed, 403);

        DB::transaction(function () use ($order, $data, $user) {
            $from = $order->status;
            $timestamps = [
                'approved' => 'accepted_at',
                'courier' => 'picked_at',
                'delivered' => 'delivered_at',
                'returned' => 'returned_at',
            ];
            $changes = ['status' => $data['status']];
            if (isset($timestamps[$data['status']])) {
                $changes[$timestamps[$data['status']]] = now();
            }
            $order->update($changes);
            OrderStatusLog::create(['tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'from_status' => $from, 'to_status' => $data['status'], 'user_id' => $user->id, 'note' => $data['note'] ?? null]);
        });

        return response()->json(['data' => $this->orderData($order->fresh('courier:id,name,phone'))]);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $request->user();
        abort_if($user->role === 'merchant' && $order->tenant_id !== $user->tenant_id, 403);
        abort_if($user->role === 'courier' && $order->courier_id !== $user->id, 403);
    }

    private function orderData(Order $order): array
    {
        return ['id' => $order->id, 'track_no' => $order->track_no, 'customer_name' => $order->customer_name_ar, 'phone' => $order->phone, 'address' => $order->address_ar, 'price' => $order->price, 'fee' => $order->fee, 'status' => $order->status, 'notes' => $order->notes, 'province_id' => $order->province_id, 'date' => $order->date?->toDateString(), 'courier' => $order->courier ? ['id' => $order->courier->id, 'name' => $order->courier->name, 'phone' => $order->courier->phone] : null];
    }
}
