<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $orders = Order::query();

        if ($user->role === 'merchant') {
            $orders->where('tenant_id', $user->tenant_id);
        } elseif ($user->role === 'courier') {
            $orders->where('courier_id', $user->id);
        }

        $statusCounts = collect(Order::STATUSES)->mapWithKeys(fn (string $status) => [$status => (clone $orders)->where('status', $status)->count()]);

        $totals = [
            'orders' => (clone $orders)->count(),
            'value' => (clone $orders)->sum('price'),
            'delivered_value' => (clone $orders)->where('status', 'delivered')->sum('price'),
            'available_balance' => $user->wallet?->balance ?? 0,
            'budget' => $user->wallet?->budget ?? 0,
            'couriers' => $user->isAdmin() ? User::query()->where('role', 'courier')->where('status', 'active')->count() : null,
            'fees' => $user->isAdmin() ? Transaction::query()->where('type', 'delivery_fee')->sum('amount') : null,
        ];

        return response()->json(['data' => [
            'role' => $user->role,
            'orders' => $statusCounts,
            'totals' => $totals,
            'orders_count' => $totals['orders'],
            'delivered_value' => $totals['delivered_value'],
            'wallet_balance' => $totals['available_balance'],
            'budget' => $totals['budget'],
            'admin_fee' => $totals['fees'],
            'statuses' => $statusCounts,
        ]]);
    }
}
