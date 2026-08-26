<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function finance(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->role === 'admin' && $request->user()->isActiveUser(),
            403,
        );
        $from = $request->date('from')?->startOfDay() ?? now()->subDays(29)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        $orders = Order::withoutGlobalScope(TenantScope::class)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $transactions = Transaction::withoutGlobalScope(TenantScope::class)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        return response()->json(['data' => [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'orders_total' => (clone $orders)->count(),
            'delivered_total' => (clone $orders)->where('status', 'delivered')->count(),
            'delivered_value' => (clone $orders)->where('status', 'delivered')->sum('price'),
            'fees_total' => (clone $orders)->where('status', 'delivered')->sum('fee'),
            'cash_in' => (clone $transactions)->where('direction', 1)->sum('amount'),
            'cash_out' => (clone $transactions)->where('direction', -1)->sum('amount'),
        ]]);
    }
}
