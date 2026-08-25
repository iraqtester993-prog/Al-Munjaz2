<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes courier assignment a single atomic business operation.
 *
 * Whether an order is claimed from the mobile app or assigned by an
 * administrator, the same province check, budget reservation, audit trail
 * and workflow transition must be applied.  Otherwise a dashboard-assigned
 * order could bypass the courier wallet rules used by the PWA.
 */
class CourierOrderAssignmentService
{
    public function assign(
        Order $order,
        User $courier,
        User $actor,
        string $note,
        bool $requireOnDuty = false,
    ): void {
        DB::transaction(function () use ($order, $courier, $actor, $note, $requireOnDuty): void {
            $delivery = Order::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);

            $courier = User::query()
                ->lockForUpdate()
                ->findOrFail($courier->id);

            $this->ensure(
                $courier->role === 'courier' && $courier->status === 'active',
                'المستخدم المختار ليس مندوباً نشطاً.'
            );
            $this->ensure(! $requireOnDuty || $courier->is_online, 'فعّل حالة الاستلام أولاً لاستلام الطلب.');
            $this->ensure(
                app(CourierOrderAccess::class)->canClaim($delivery, $courier),
                'هذا الطلب لم يعد متاحاً لهذا المندوب.'
            );

            $wallet = Wallet::query()
                ->where('user_id', $courier->id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = Wallet::create([
                    'user_id' => $courier->id,
                    'balance' => 0,
                    'budget' => 0,
                ]);
            }

            $this->ensure($wallet->budget >= $delivery->price, 'ميزانية المندوب أقل من قيمة الطلب.');

            $delivery->update(['courier_id' => $courier->id]);
            app(OrderWorkflowService::class)->changeStatus($delivery, 'approved', $actor, $note);

            $wallet->decrement('budget', $delivery->price);

            Transaction::create([
                'tenant_id' => $courier->tenant_id,
                'user_id' => $courier->id,
                'type' => 'paid_order',
                'amount' => $delivery->price,
                'direction' => -1,
                'ref' => $delivery->track_no,
                'order_id' => $delivery->id,
                'date' => today(),
                'note' => 'حجز ميزانية لاستلام الطلب',
            ]);
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['order' => [$message]]);
        }
    }
}
