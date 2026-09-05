<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CourierOrderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        // Defence in depth: this endpoint must stay safe even if its route
        // middleware is changed later. Branch-dashboard users have their own
        // scoped browser portal and never use the mobile API surface.
        abort_unless(
            $user?->isActiveUser()
                && ($user->isSuperAdmin() || $user->role === 'merchant' || $user->role === 'courier'),
            403,
        );

        // Super administrators see the cross-tenant operating picture.
        // Merchants and couriers are intentionally narrowed to their own
        // authorised work queues below.
        $orders = $user->isSuperAdmin()
            ? Order::withoutGlobalScope(TenantScope::class)
            : Order::query();

        if ($user->role === 'merchant') {
            $orders->where('tenant_id', $user->tenant_id);
        } elseif ($user->role === 'courier') {
            $orders = app(CourierOrderAccess::class)->assigned($user);
        }

        $statusCounts = collect(Order::STATUSES)->mapWithKeys(fn (string $status) => [$status => (clone $orders)->where('status', $status)->count()]);

        $totals = [
            'orders' => (clone $orders)->count(),
            'value' => (clone $orders)->sum('price'),
            'delivered_value' => (clone $orders)->where('status', 'delivered')->sum('price'),
            'available_balance' => $user->wallet?->balance ?? 0,
            'budget' => $user->wallet?->budget ?? 0,
            'budget_balance' => $user->wallet?->budget_balance ?? 0,
            'couriers' => $user->isSuperAdmin()
                ? User::query()->whereIn('role', User::COURIER_ROLES)->where('status', 'active')->count()
                : null,
            'fees' => $user->isSuperAdmin()
                ? Transaction::withoutGlobalScope(TenantScope::class)->where('type', 'commission')->sum('amount')
                : null,
        ];

        return response()->json(['data' => [
            'role' => $user->role,
            'orders' => $statusCounts,
            'totals' => $totals,
            'orders_count' => $totals['orders'],
            'delivered_value' => $totals['delivered_value'],
            'wallet_balance' => $totals['available_balance'],
            'budget' => $totals['budget'],
            'budget_balance' => $totals['budget_balance'],
            'admin_fee' => $totals['fees'],
            'statuses' => $statusCounts,
        ]]);
    }
}
