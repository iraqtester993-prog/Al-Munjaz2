<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
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
            $this->refundAdministrativeDeductionForReoffer($delivery, $previousCourier);

            $availabilityMinutes = max(1, min((int) $this->settingForOrder($delivery, 'order_expiry_minutes', 30), 1440));
            $offerOpenedAt = now();
            $delivery->forceFill([
                'courier_id' => null,
                'pickup_courier_id' => null,
                'delivery_courier_id' => null,
                'accepted_at' => null,
                // The next acceptance receives a fresh immutable snapshot;
                // the prior one is retained in the financial ledger only.
                'admin_deduction_applied' => null,
                'offer_opened_at' => $offerOpenedAt,
                'pickup_deadline_at' => $offerOpenedAt->copy()->addMinutes($availabilityMinutes),
            ])->save();

            app(OrderWorkflowService::class)->changeStatus($delivery, 'pending', $actor, $reason, true);

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
        $courierId = (int) ($courier?->id ?: $order->courier_id);

        app(CourierBudgetHoldService::class)->releaseOutstandingForCourier(
            $order,
            $courierId,
            'إعادة مبلغ حجز الطلب إلى رصيد الميزانية بعد انتهاء وقت الوصول وإعادة الطرح.',
        );
    }

    /**
     * A courier who never reached the merchant has not performed the job.
     * Re-offering the same order must therefore reverse the acceptance-time
     * Qi deduction once, alongside returning its budget reservation.
     */
    private function refundAdministrativeDeductionForReoffer(Order $order, ?User $courier): void
    {
        $courierId = (int) $order->courier_id;
        $amount = max(0, (int) ($order->admin_deduction_applied ?? 0));

        if ($courierId <= 0 || $amount === 0) {
            return;
        }

        $charged = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $courierId)
            ->where('type', 'commission')
            ->where('direction', -1)
            ->exists();
        $alreadyRefunded = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $courierId)
            ->where('type', 'commission_refund')
            ->where('direction', 1)
            ->exists();

        if (! $charged || $alreadyRefunded) {
            return;
        }

        $wallet = Wallet::query()->where('user_id', $courierId)->lockForUpdate()->first();
        if (! $wallet) {
            return;
        }

        $wallet->increment('balance', $amount);

        Transaction::create([
            'tenant_id' => $courier?->tenant_id,
            'user_id' => $courierId,
            'type' => 'commission_refund',
            'amount' => $amount,
            'direction' => 1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => 'إعادة استقطاع الإدارة لأن الطلب أُعيد طرحه قبل استلامه.',
        ]);

        if ($courier) {
            Notification::create([
                'tenant_id' => $courier->tenant_id,
                'user_id' => $courier->id,
                'type' => 'finance',
                'title_ar' => 'إعادة استقطاع الإدارة',
                'title_en' => 'Platform deduction refunded',
                'title_ku' => 'گەڕاندنەوەی کەمکردنەوەی بەڕێوەبەرایەتی',
                'body_ar' => 'أُعيد مبلغ '.number_format($amount).' د.ع إلى رصيد Qi لأن الطلب '.$order->track_no.' أُعيد طرحه قبل استلامه.',
                'body_en' => number_format($amount).' IQD was returned to your Qi balance because '.$order->track_no.' was re-offered before pickup.',
                'body_ku' => number_format($amount).' د.ع گەڕێندرایەوە بۆ باڵانسی Qi ـت چونکە '.$order->track_no.' پێش وەرگرتن دووبارە پێشکەش کرا.',
                'data' => ['order_id' => $order->id, 'type' => 'commission_refund', 'amount' => $amount],
            ]);
        }
    }

    private function settingForOrder(Order $order, string $key, mixed $default): mixed
    {
        $branchId = (int) ($order->branch_id ?: $order->origin_branch_id);

        return app(BranchSettingsResolver::class)
            ->getForOperationalBranch($branchId > 0 ? $branchId : null, $key, $default);
    }
}
