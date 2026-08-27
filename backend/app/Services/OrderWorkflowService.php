<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\OrderStatusLog;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer for an order lifecycle.  Keeping this in one service means
 * the mobile application and the administration dashboard leave the same
 * auditable trail.
 */
class OrderWorkflowService
{
    private const STATUS_TO_STAGE = [
        'pending' => 'created',
        'approved' => 'awaiting_pickup',
        'courier' => 'out_for_delivery',
        'delivered' => 'delivered',
        'returned' => 'returned',
        'cancelled' => 'cancelled',
        'damaged' => 'damaged',
        'rejected' => 'rejected',
    ];

    public function changeStatus(Order $order, string $status, User $actor, ?string $note = null): void
    {
        if (! in_array($status, Order::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'حالة الطلب غير صحيحة.']);
        }

        DB::transaction(function () use ($order, $status, $actor, $note) {
            // The courier has a different tenant from the merchant that owns
            // the order.  Reload without the tenant visibility scope after
            // authorisation has already happened in the calling controller.
            $order = Order::withoutGlobalScope(TenantScope::class)->findOrFail($order->id);
            $fromStatus = $order->status;
            $fromStage = $order->workflow_stage;

            if ($fromStatus === $status) {
                return;
            }

            $this->ensureValidStatusTransition($order, $status, $actor, $note);

            $updates = [
                'status' => $status,
                'workflow_stage' => self::STATUS_TO_STAGE[$status] ?? $order->workflow_stage,
            ];

            if ($status === 'approved') {
                $updates['accepted_at'] = now();
            }
            if ($status === 'courier') {
                $assignedCourierId = $order->courier_id;
                if (! $assignedCourierId && in_array($actor->role, User::DIRECT_ORDER_COURIER_ROLES, true)) {
                    $assignedCourierId = $actor->id;
                }
                $this->ensure(
                    (bool) $assignedCourierId,
                    'يجب تعيين مندوب للطلب قبل بدء مرحلة التوصيل.'
                );
                $updates['picked_at'] = now();
                $updates['courier_id'] = $assignedCourierId;
                $updates['delivery_courier_id'] = $order->delivery_courier_id ?: $assignedCourierId;
            }
            if ($status === 'delivered') {
                $this->ensure(
                    (bool) ($order->delivery_courier_id ?: $order->courier_id),
                    'يجب تعيين مندوب وتسليم مرحلة الاستلام قبل تسجيل الطلب كمسلّم.'
                );
                $updates['delivered_at'] = now();
            }
            if ($status === 'returned') {
                $updates['returned_at'] = now();
            }

            $order->update($updates);

            OrderStatusLog::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $status,
                'user_id' => $actor->id,
                'note' => $note,
            ]);

            OrderMovement::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'from_branch_id' => $order->origin_branch_id,
                'to_branch_id' => $order->destination_branch_id,
                'actor_id' => $actor->id,
                'stage' => $order->workflow_stage,
                'note' => $note,
                'meta' => ['from_status' => $fromStatus, 'to_status' => $status, 'from_stage' => $fromStage],
                'occurred_at' => now(),
            ]);

            ActivityLog::create([
                'tenant_id' => $order->tenant_id,
                'user_id' => $actor->id,
                'action' => 'order.status',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'data' => ['from' => $fromStatus, 'to' => $status, 'stage' => $order->workflow_stage],
                'ip' => request()->ip(),
            ]);

            $labels = [
                'pending' => 'الطلب بانتظار المراجعة',
                'approved' => 'تم اعتماد الطلب وينتظر الاستلام',
                'courier' => 'الطلب خرج مع مندوب التوصيل',
                'delivered' => 'تم تسليم الطلب بنجاح',
                'returned' => 'تم إرجاع الطلب',
                'cancelled' => 'تم إلغاء الطلب',
                'damaged' => 'تم تسجيل الطلب كتالف',
                'rejected' => 'تم رفض الطلب',
            ];

            Notification::create([
                'tenant_id' => $order->tenant_id,
                'user_id' => $order->merchant_id,
                'type' => 'order',
                'title_ar' => $order->track_no,
                'title_en' => $order->track_no,
                'title_ku' => $order->track_no,
                'body_ar' => $labels[$status],
                'body_en' => $labels[$status],
                'body_ku' => $labels[$status],
                'data' => ['order_id' => $order->id, 'status' => $status, 'stage' => $order->workflow_stage],
            ]);

            // A delivered order is a financial event, not only a visual
            // status.  Post the courier collection and the merchant's net
            // receivable here, inside the same transaction as the workflow
            // trail.  The order lock and ledger guard make a retried request
            // safe; it can never credit a wallet twice.
            if ($status === 'delivered') {
                $this->postDeliveredSettlement($order);
                // Points are an auditable, non-monetary reward. The service
                // has a source lock, so an HTTP retry can never award a
                // courier twice for the same completed delivery.
                app(LoyaltyPointService::class)->creditForDelivery($order);
            }

            // A courier's order-value reservation must be released for every
            // terminal outcome after assignment. The ledger guard inside the
            // helper makes this safe when a status post is retried.
            if (in_array($status, ['delivered', 'returned', 'cancelled', 'damaged', 'rejected'], true) && $order->courier_id) {
                $this->releaseCourierBudgetIfHeld($order, $status);
            }
        });
    }

    /**
     * Start a courier return without treating a button press as proof that the
     * parcel has physically reached the merchant. The returned status records
     * the failed delivery; the separate confirmation below records the
     * handback and only then posts an optional return fee to the ledger.
     */
    public function startCourierReturn(Order $order, User $actor, int $returnFee = 0, ?string $note = null): void
    {
        DB::transaction(function () use ($order, $actor, $returnFee, $note): void {
            $delivery = Order::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->ensureCourierReturnCanStart($delivery, $actor);

            $returnFee = max(0, $returnFee);
            $feeNote = $returnFee > 0
                ? 'تم اختيار إرجاع بأجرة '.number_format($returnFee).' د.ع.'
                : 'تم اختيار إرجاع بدون أجرة توصيل.';
            $entryNote = $note ? $feeNote.' '.$note : $feeNote;
            $fromStatus = $delivery->status;
            $fromStage = $delivery->workflow_stage;

            $delivery->forceFill([
                'status' => 'returned',
                'workflow_stage' => 'return_pending_merchant',
                'returned_at' => now(),
                // return_fee is the immutable pricing quote; the courier's
                // actual fee choice belongs in return_fee_applied.
                'return_fee_applied' => $returnFee,
                'returned_to_merchant_at' => null,
                'return_fee_charged_at' => null,
            ])->save();

            OrderStatusLog::create([
                'tenant_id' => $delivery->tenant_id,
                'order_id' => $delivery->id,
                'from_status' => $fromStatus,
                'to_status' => 'returned',
                'user_id' => $actor->id,
                'note' => $entryNote,
            ]);

            OrderMovement::withoutGlobalScopes()->create([
                'tenant_id' => $delivery->tenant_id,
                'order_id' => $delivery->id,
                'from_branch_id' => $this->returnFromBranch($delivery),
                'to_branch_id' => $this->returnToBranch($delivery),
                'actor_id' => $actor->id,
                'stage' => 'return_pending_merchant',
                'note' => 'الطلب بانتظار تأكيد إعادته للتاجر. '.$entryNote,
                'meta' => [
                    'return_fee_quote' => (int) $delivery->return_fee,
                    'return_fee_applied' => $returnFee,
                    'fee_status' => $returnFee > 0 ? 'pending_confirmation' : 'none',
                    'from_status' => $fromStatus,
                    'from_stage' => $fromStage,
                ],
                'occurred_at' => now(),
            ]);

            ActivityLog::create([
                'tenant_id' => $delivery->tenant_id,
                'user_id' => $actor->id,
                'action' => 'order.return_started',
                'subject_type' => 'order',
                'subject_id' => $delivery->id,
                'data' => [
                    'return_fee_quote' => (int) $delivery->return_fee,
                    'return_fee_applied' => $returnFee,
                    'from_status' => $fromStatus,
                ],
                'ip' => request()->ip(),
            ]);

            if ($delivery->merchant_id) {
                $amount = number_format($returnFee);
                Notification::create([
                    'tenant_id' => $delivery->tenant_id,
                    'user_id' => $delivery->merchant_id,
                    'type' => 'order',
                    'title_ar' => $delivery->track_no,
                    'title_en' => $delivery->track_no,
                    'title_ku' => $delivery->track_no,
                    'body_ar' => $returnFee > 0
                        ? 'تم تسجيل إرجاع الطلب بأجرة '.$amount.' د.ع. بانتظار تأكيد تسليمه إليك.'
                        : 'تم تسجيل إرجاع الطلب بدون أجرة. بانتظار تأكيد تسليمه إليك.',
                    'body_en' => $returnFee > 0
                        ? 'The return was recorded with a '.number_format($returnFee).' IQD fee and awaits merchant handback confirmation.'
                        : 'The return was recorded with no fee and awaits merchant handback confirmation.',
                    'body_ku' => $returnFee > 0
                        ? 'گەڕاندنەوەکە بە کرێی '.number_format($returnFee).' د.ع تۆمار کرا و چاوەڕێی دڵنیابوونەوەی گەیاندنە بۆ بازرگانە.'
                        : 'گەڕاندنەوەکە بەبێ کرێ تۆمار کرا و چاوەڕێی دڵنیابوونەوەی گەیاندنە بۆ بازرگانە.',
                    'data' => [
                        'order_id' => $delivery->id,
                        'status' => 'returned',
                        'stage' => 'return_pending_merchant',
                        'return_fee_quote' => (int) $delivery->return_fee,
                        'return_fee_applied' => $returnFee,
                    ],
                ]);
            }

            // The order-value budget hold is no longer needed after a failed
            // delivery. This method is idempotent, so a repeat browser post
            // cannot release the same reservation twice.
            $this->releaseCourierBudgetIfHeld($delivery, 'returned');
        });
    }

    /**
     * Confirm that the parcel has actually been handed back to the merchant.
     * A selected fee is only posted here, once, after the physical event has
     * been confirmed; it is never a client-side wallet mutation.
     */
    public function confirmCourierReturnToMerchant(Order $order, User $actor, ?string $note = null): void
    {
        DB::transaction(function () use ($order, $actor, $note): void {
            $delivery = Order::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->ensure(
                $delivery->status === 'returned'
                    && (int) $delivery->courier_id === (int) $actor->id,
                'لا يمكن تأكيد إعادة هذا الطلب إلى التاجر.'
            );
            $this->ensure(
                $delivery->returned_to_merchant_at === null,
                'تم تأكيد إعادة الطلب إلى التاجر مسبقاً.'
            );

            $returnFee = max(0, (int) $delivery->return_fee_applied);
            $confirmedAt = now();
            $confirmationNote = $note ?: 'تم تأكيد تسليم الطلب المرتجع إلى التاجر.';

            $delivery->forceFill([
                'workflow_stage' => 'returned_to_merchant',
                'returned_to_merchant_at' => $confirmedAt,
            ])->save();

            $feePosted = false;
            if ($returnFee > 0) {
                $feePosted = Transaction::withoutGlobalScope(TenantScope::class)
                    ->where('order_id', $delivery->id)
                    ->where('user_id', $actor->id)
                    ->where('type', 'delivery_fee')
                    ->where('direction', -1)
                    ->exists();

                if (! $feePosted) {
                    // The reference app shows this as a courier delivery-fee
                    // debit. Persist it in the immutable ledger only after
                    // confirmation; verified cash and wallet settlements are
                    // still controlled by the finance workflow.
                    Transaction::create([
                        'tenant_id' => $actor->tenant_id,
                        'user_id' => $actor->id,
                        'type' => 'delivery_fee',
                        'amount' => $returnFee,
                        'direction' => -1,
                        'ref' => $delivery->track_no,
                        'order_id' => $delivery->id,
                        'date' => today(),
                        'note' => 'أجرة توصيل للطلب المرتجع بعد تأكيد إعادته للتاجر.',
                    ]);
                }

                $delivery->forceFill(['return_fee_charged_at' => $confirmedAt])->save();
            }

            OrderMovement::withoutGlobalScopes()->create([
                'tenant_id' => $delivery->tenant_id,
                'order_id' => $delivery->id,
                'from_branch_id' => $this->returnFromBranch($delivery),
                'to_branch_id' => $this->returnToBranch($delivery),
                'actor_id' => $actor->id,
                'stage' => 'returned_to_merchant',
                'note' => $confirmationNote,
                'meta' => [
                    'return_fee_quote' => (int) $delivery->return_fee,
                    'return_fee_applied' => $returnFee,
                    'fee_ledger_posted' => $returnFee > 0,
                    'fee_already_posted' => $feePosted,
                ],
                'occurred_at' => $confirmedAt,
            ]);

            ActivityLog::create([
                'tenant_id' => $delivery->tenant_id,
                'user_id' => $actor->id,
                'action' => 'order.return_confirmed_to_merchant',
                'subject_type' => 'order',
                'subject_id' => $delivery->id,
                'data' => [
                    'return_fee_quote' => (int) $delivery->return_fee,
                    'return_fee_applied' => $returnFee,
                    'fee_posted' => $returnFee > 0,
                ],
                'ip' => request()->ip(),
            ]);

            if ($delivery->merchant_id) {
                Notification::create([
                    'tenant_id' => $delivery->tenant_id,
                    'user_id' => $delivery->merchant_id,
                    'type' => 'order',
                    'title_ar' => $delivery->track_no,
                    'title_en' => $delivery->track_no,
                    'title_ku' => $delivery->track_no,
                    'body_ar' => $returnFee > 0
                        ? 'تم تأكيد إعادة الطلب إليك. سُجلت أجرة الإرجاع بقيمة '.number_format($returnFee).' د.ع في السجل المالي.'
                        : 'تم تأكيد إعادة الطلب إليك بدون أجرة توصيل.',
                    'body_en' => $returnFee > 0
                        ? 'The return to the merchant was confirmed. A '.number_format($returnFee).' IQD return delivery fee was recorded.'
                        : 'The return to the merchant was confirmed with no delivery fee.',
                    'body_ku' => $returnFee > 0
                        ? 'گەڕاندنەوەکە بۆ بازرگان پشتڕاست کرایەوە. کرێی گەڕاندنەوەی '.number_format($returnFee).' د.ع لە تۆماری دارایی تۆمار کرا.'
                        : 'گەڕاندنەوەکە بۆ بازرگان بەبێ کرێی گواستنەوە پشتڕاست کرایەوە.',
                    'data' => [
                        'order_id' => $delivery->id,
                        'status' => 'returned',
                        'stage' => 'returned_to_merchant',
                        'return_fee_quote' => (int) $delivery->return_fee,
                        'return_fee_applied' => $returnFee,
                    ],
                ]);
            }
        });
    }

    private function ensureCourierReturnCanStart(Order $order, User $actor): void
    {
        $this->ensure(
            $order->status === 'courier'
                && (int) $order->courier_id === (int) $actor->id,
            'لا يمكن بدء إرجاع هذا الطلب.'
        );
    }

    private function returnFromBranch(Order $order): ?int
    {
        return $order->destination_branch_id ?: $order->branch_id ?: $order->origin_branch_id;
    }

    private function returnToBranch(Order $order): ?int
    {
        return $order->origin_branch_id ?: $order->branch_id ?: $order->destination_branch_id;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['order' => [$message]]);
        }
    }

    /**
     * Normal workflow transitions stay explicit. An administrator may record
     * a documented correction for a physically verified exceptional case,
     * but an empty click in the dashboard can never jump a delivery straight
     * into a terminal financial status.
     */
    private function ensureValidStatusTransition(Order $order, string $to, User $actor, ?string $note): void
    {
        $allowed = match ($order->status) {
            'pending' => ['approved', 'cancelled', 'rejected'],
            'approved' => ['courier', 'cancelled', 'rejected'],
            'courier' => ['delivered', 'cancelled', 'damaged', 'rejected'],
            default => [],
        };

        if (in_array($to, $allowed, true)) {
            return;
        }

        $this->ensure(
            $actor->isAdmin() && filled($note),
            'الانتقال التشغيلي غير مسموح. التصحيح الإداري يتطلب ملاحظة توثيقية.'
        );
    }

    protected function releaseCourierBudgetIfHeld(Order $order, string $status): void
    {
        $held = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $order->courier_id)
            ->where('type', 'paid_order')
            ->where('direction', -1)
            ->exists();

        $released = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $order->courier_id)
            ->where('type', 'budget_release')
            ->exists();

        if (! $held || $released) {
            return;
        }

        $courier = User::withoutGlobalScopes()->find($order->courier_id);
        $wallet = Wallet::query()->where('user_id', $order->courier_id)->lockForUpdate()->first();

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
            'note' => match ($status) {
                'delivered' => 'إعادة ميزانية بعد التسليم',
                'cancelled' => 'إعادة ميزانية بعد إلغاء الطلب',
                'damaged' => 'إعادة ميزانية بعد تسجيل الطلب كتالف',
                'rejected' => 'إعادة ميزانية بعد رفض الطلب',
                default => 'إعادة ميزانية بعد الإرجاع',
            },
        ]);
    }

    /**
     * Record a delivered COD order without mixing physical courier cash with
     * the courier's prepaid Qi credit:
     *
     * - the courier collection is the order value after the company fee;
     * - the company fee is debited from the courier's Qi wallet; and
     * - the merchant receives the full order value because the courier pays
     *   it from the cash budget used when accepting the job.
     *
     * Every entry is guarded independently so retries cannot debit a wallet
     * or post a collection twice.
     */
    protected function postDeliveredSettlement(Order $order): void
    {
        $orderValue = max(0, (int) $order->price);
        $fee = min($orderValue, max(0, (int) $order->fee));
        $netCollection = $orderValue - $fee;
        $courierId = $order->delivery_courier_id ?: $order->courier_id;

        if ($courierId) {
            $courier = User::withoutGlobalScopes()->find($courierId);

            if ($courier && $courier->isCourierRole()) {
                $alreadyCollected = Transaction::withoutGlobalScope(TenantScope::class)
                    ->where('order_id', $order->id)
                    ->where('user_id', $courier->id)
                    ->where('type', 'collected')
                    ->where('direction', 1)
                    ->exists();

                $feePosted = Transaction::withoutGlobalScope(TenantScope::class)
                    ->where('order_id', $order->id)
                    ->where('user_id', $courier->id)
                    ->where('type', 'delivery_fee')
                    ->where('direction', -1)
                    ->exists();

                if (! $feePosted && $fee > 0) {
                    Wallet::firstOrCreate(['user_id' => $courier->id], ['balance' => 0, 'budget' => 0]);
                    $courierWallet = Wallet::query()
                        ->where('user_id', $courier->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $this->ensure(
                        (int) $courierWallet->balance >= $fee,
                        'رصيد المندوب لا يغطي رسوم الشركة لهذا الطلب.'
                    );

                    $courierWallet->decrement('balance', $fee);

                    Transaction::create([
                        'tenant_id' => $courier->tenant_id ?? $order->tenant_id,
                        'user_id' => $courier->id,
                        'type' => 'delivery_fee',
                        'amount' => $fee,
                        'direction' => -1,
                        'ref' => $order->track_no,
                        'order_id' => $order->id,
                        'date' => today(),
                        'note' => 'استقطاع رسوم الشركة من رصيد Qi بعد تسليم الطلب.',
                    ]);

                    Notification::create([
                        'tenant_id' => $courier->tenant_id,
                        'user_id' => $courier->id,
                        'type' => 'finance',
                        'title_ar' => 'استقطاع رسوم الطلب',
                        'title_en' => 'Delivery fee deducted',
                        'title_ku' => 'کرێی گەیاندن کەمکرایەوە',
                        'body_ar' => 'تم استقطاع '.number_format($fee).' د.ع من رصيد Qi للطلب '.$order->track_no.'.',
                        'body_en' => number_format($fee).' IQD was deducted from your Qi balance for '.$order->track_no.'.',
                        'body_ku' => number_format($fee).' IQD was deducted from your Qi balance for '.$order->track_no.'.',
                        'data' => ['order_id' => $order->id, 'type' => 'delivery_fee', 'amount' => $fee],
                    ]);
                }

                if (! $alreadyCollected) {
                    Transaction::create([
                        'tenant_id' => $courier->tenant_id ?? $order->tenant_id,
                        'user_id' => $courier->id,
                        'type' => 'collected',
                        'amount' => $netCollection,
                        'direction' => 1,
                        'ref' => $order->track_no,
                        'order_id' => $order->id,
                        'date' => today(),
                        'note' => 'تحصيل صافٍ بعد خصم رسوم الشركة عند تسليم الطلب.',
                    ]);
                }
            }
        }

        $merchantId = $order->merchant_id ?: $order->created_by;
        if (! $merchantId) {
            return;
        }

        $merchant = User::withoutGlobalScopes()->find($merchantId);
        if (! $merchant || $merchant->role !== 'merchant') {
            return;
        }

        $alreadyPosted = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $merchant->id)
            ->where('type', 'settlement')
            ->where('direction', 1)
            ->exists();

        // Older migrated records already have their merchant settlement.
        if ($alreadyPosted) {
            return;
        }

        Wallet::firstOrCreate(
            ['user_id' => $merchant->id],
            ['balance' => 0, 'budget' => 0],
        );
        $wallet = Wallet::query()
            ->where('user_id', $merchant->id)
            ->lockForUpdate()
            ->firstOrFail();

        Transaction::create([
            'tenant_id' => $merchant->tenant_id,
            'user_id' => $merchant->id,
            'type' => 'settlement',
            'amount' => $orderValue,
            'direction' => 1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => 'استحقاق التاجر بعد تسليم الطلب.',
        ]);

        if ($orderValue > 0) {
            $wallet->increment('balance', $orderValue);
        }

        // `tenants.wallet_balance` exists in the original schema.  Keep it
        // synchronized for older dashboard exports while all new reads use
        // the per-user wallet above as the source of truth.
        Tenant::query()
            ->whereKey($merchant->tenant_id)
            ->update(['wallet_balance' => (int) $wallet->fresh()->balance]);
    }
}
