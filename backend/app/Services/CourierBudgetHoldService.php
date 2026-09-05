<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;

/**
 * Resolves the budget reservation that belongs to one courier on one order.
 *
 * A re-offered order can have historical holds from more than one courier.
 * The ledger does not link a release row to a particular hold row, so the
 * safe compatibility rule is to release only the current courier's net
 * outstanding reservation (all paid_order debits less prior budget releases).
 */
class CourierBudgetHoldService
{
    public function releaseOutstandingForCourier(Order $order, int $courierId, string $note): void
    {
        if ($courierId <= 0) {
            return;
        }

        // Keep historical reservations intact: older releases used price +
        // delivery fee while new claims use price only. The ledger balance is
        // therefore the source of truth rather than the current order price.
        $entries = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $courierId)
            ->whereIn('type', ['paid_order', 'budget_release'])
            ->lockForUpdate()
            ->get(['type', 'amount', 'direction']);

        $held = (int) $entries
            ->where('type', 'paid_order')
            ->where('direction', -1)
            ->sum('amount');
        $released = (int) $entries
            ->where('type', 'budget_release')
            ->where('direction', 1)
            ->sum('amount');
        $outstanding = max(0, $held - $released);

        if ($outstanding === 0) {
            return;
        }

        $wallet = Wallet::query()
            ->where('user_id', $courierId)
            ->lockForUpdate()
            ->first();

        // A paid_order entry is only created after a wallet has been locked.
        // Do not write a compensating ledger row if that invariant is broken;
        // keeping the outstanding reservation visible is safer than crediting
        // a wallet that is no longer present.
        if (! $wallet) {
            return;
        }

        $wallet->increment('budget_balance', $outstanding);

        $courier = User::withoutGlobalScopes()->find($courierId);
        Transaction::create([
            'tenant_id' => $courier?->tenant_id ?? $order->tenant_id,
            'user_id' => $courierId,
            'type' => 'budget_release',
            'amount' => $outstanding,
            'direction' => 1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => $note,
        ]);
    }
}
