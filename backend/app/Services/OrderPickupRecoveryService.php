<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handles the single safe recovery path for a courier who has not reached
 * the merchant before the pickup deadline.  "Late" remains an exception,
 * not a sixth customer-facing order status.  Re-offering releases the exact
 * budget reservation once, writes the normal status timeline, and leaves a
 * notification trail for both the former courier and the merchant.
 */
class OrderPickupRecoveryService
{
    public function reoffer(Order $order, User $actor, ?string $note = null): void
    {
        DB::transaction(function () use ($order, $actor, $note): void {
            $delivery = Order::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($delivery->status !== 'approved' || ! $delivery->courier_id) {
                throw ValidationException::withMessages([
                    'order' => ['يمكن إعادة طرح طلب تم قبوله ولم يستلمه المندوب فقط.'],
                ]);
            }

            if (! $delivery->pickup_deadline_at || $delivery->pickup_deadline_at->isFuture()) {
                throw ValidationException::withMessages([
                    'order' => ['لا يمكن إعادة طرح الطلب قبل انتهاء وقت الوصول إلى التاجر.'],
                ]);
            }

            $previousCourierId = (int) $delivery->courier_id;
            $previousCourier = User::withoutGlobalScopes()->find($previousCourierId);
            $reason = filled($note)
                ? trim((string) $note)
                : 'تأخر المندوب عن الوصول إلى التاجر؛ أُعيد طرح الطلب من لوحة الإدارة.';

            $this->releaseBudgetReservation($delivery, $previousCourier);

            $availabilityMinutes = max(1, min((int) Setting::get('order_expiry_minutes', 30), 1440));
            $delivery->forceFill([
                'courier_id' => null,
                'pickup_courier_id' => null,
                'delivery_courier_id' => null,
                'accepted_at' => null,
                'pickup_deadline_at' => now()->addMinutes($availabilityMinutes),
            ])->save();

            app(OrderWorkflowService::class)->changeStatus($delivery, 'pending', $actor, $reason);

            if ($previousCourier) {
                Notification::create([
                    'tenant_id' => $previousCourier->tenant_id,
                    'user_id' => $previousCourier->id,
                    'type' => 'order',
                    'title_ar' => $delivery->track_no,
                    'title_en' => $delivery->track_no,
                    'title_ku' => $delivery->track_no,
                    'body_ar' => 'انتهى وقت الوصول إلى التاجر، لذلك أُعيد طرح الطلب لمندوب آخر.',
                    'body_en' => 'The merchant pickup time expired, so this order was offered again.',
                    'body_ku' => 'کاتی گەیشتن بە بازرگان بەسەرچوو، بۆیە داواکارییەکە دووبارە پێشکەش کرا.',
                    'data' => ['order_id' => $delivery->id, 'status' => 'pending', 'event' => 'pickup_reoffered'],
                ]);
            }
        });
    }

    private function releaseBudgetReservation(Order $order, ?User $courier): void
    {
        $held = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $order->courier_id)
            ->where('type', 'paid_order')
            ->where('direction', -1)
            ->exists();

        $alreadyReleased = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $order->courier_id)
            ->where('type', 'budget_release')
            ->exists();

        if (! $held || $alreadyReleased) {
            return;
        }

        $wallet = Wallet::query()
            ->where('user_id', $order->courier_id)
            ->lockForUpdate()
            ->first();

        if ($wallet) {
            $wallet->increment('budget', $order->price);
        }

        Transaction::create([
            'tenant_id' => $courier?->tenant_id,
            'user_id' => $order->courier_id,
            'type' => 'budget_release',
            'amount' => $order->price,
            'direction' => 1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => 'إعادة ميزانية بعد انتهاء وقت الوصول إلى التاجر وإعادة طرح الطلب.',
        ]);
    }
}
