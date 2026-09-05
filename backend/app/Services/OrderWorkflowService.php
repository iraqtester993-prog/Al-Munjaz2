<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\OrderStatusLog;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
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

    public function changeStatus(
        Order $order,
        string $status,
        User $actor,
        ?string $note = null,
        bool $allowApprovedReoffer = false,
    ): void
    {
        if (! in_array($status, Order::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'حالة الطلب غير صحيحة.']);
        }

        DB::transaction(function () use ($order, $status, $actor, $note, $allowApprovedReoffer) {
            // The courier has a different tenant from the merchant that owns
            // the order.  Reload without the tenant visibility scope after
            // authorisation has already happened in the calling controller.
            // Status transitions can post several ledger rows. Serialise the
            // order itself so two concurrent requests cannot both pass the
            // idempotency checks before either row is written.
            $order = Order::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);
            $fromStatus = $order->status;
            $fromStage = $order->workflow_stage;

            if ($fromStatus === $status) {
                return;
            }

            $this->ensureValidStatusTransition($order, $status, $allowApprovedReoffer);

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
            }
            if ($status === 'delivered') {
                $this->ensure(
                    (bool) ($order->courier_id ?: $order->delivery_courier_id ?: $order->pickup_courier_id),
                    'يجب تعيين مندوب للطلب قبل تسجيله كمسلّم.'
                );
                $updates['delivered_at'] = now();
            }
            if ($status === 'returned') {
                $updates['returned_at'] = now();
            }

            $order->update($updates);

            // New claims reserve the product value at acceptance. Keep this
            // call for approved orders that existed before that policy was
            // introduced: it creates the missing reservation once and is a
            // no-op for every newly claimed order.
            if ($status === 'courier') {
                $this->reserveCourierBudgetForPickup($order);
            }

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

            if ($status === 'returned') {
                $this->postReturnedAdministrativeDeduction($order);
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
    public function startCourierReturn(Order $order, User $actor, string $feeMode, string $returnReason): void
    {
        DB::transaction(function () use ($order, $actor, $feeMode, $returnReason): void {
            $delivery = Order::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->ensureCourierReturnCanStart($delivery, $actor);

            $feeMode = $feeMode === 'fee' ? 'fee' : 'none';
            $returnReason = trim($returnReason);
            $this->ensure($returnReason !== '', 'سبب إرجاع الطلب مطلوب.');

            // The courier chooses only whether the already-quoted delivery
            // fee applies. Never accept a browser-supplied amount here.
            $returnFee = $feeMode === 'fee' ? max(0, (int) $delivery->return_fee) : 0;
            $feeNote = $feeMode === 'fee'
                ? 'تم اختيار إرجاع بأجرة التوصيل المعتمدة '.number_format($returnFee).' د.ع.'
                : 'تم اختيار إرجاع بدون أجرة توصيل.';
            $entryNote = $feeNote.' سبب الإرجاع: '.$returnReason;
            $fromStatus = $delivery->status;
            $fromStage = $delivery->workflow_stage;

            $delivery->forceFill([
                'status' => 'returned',
                'workflow_stage' => 'return_pending_merchant',
                'returned_at' => now(),
                // return_fee is the immutable pricing quote; the courier's
                // actual fee choice belongs in return_fee_applied.
                'return_fee_applied' => $returnFee,
                'return_fee_mode' => $feeMode,
                'return_reason' => $returnReason,
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
                    'return_fee_mode' => $feeMode,
                    'return_reason' => $returnReason,
                    'fee_status' => $feeMode === 'fee' ? 'pending_confirmation' : 'none',
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
                    'return_fee_mode' => $feeMode,
                    'return_reason' => $returnReason,
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
                    'body_ar' => $feeMode === 'fee'
                        ? 'تم تسجيل إرجاع الطلب بأجرة التوصيل المعتمدة '.$amount.' د.ع. السبب: '.$returnReason
                        : 'تم تسجيل إرجاع الطلب بدون أجرة توصيل. السبب: '.$returnReason,
                    'body_en' => $feeMode === 'fee'
                        ? 'The return was recorded with the quoted '.number_format($returnFee).' IQD delivery fee. Reason: '.$returnReason
                        : 'The return was recorded with no delivery fee. Reason: '.$returnReason,
                    'body_ku' => $feeMode === 'fee'
                        ? 'گەڕاندنەوەکە بە کرێی گواستنەوەی دیاریکراوی '.number_format($returnFee).' د.ع تۆمار کرا. هۆکار: '.$returnReason
                        : 'گەڕاندنەوەکە بەبێ کرێی گواستنەوە تۆمار کرا. هۆکار: '.$returnReason,
                    'data' => [
                        'order_id' => $delivery->id,
                        'status' => 'returned',
                        'stage' => 'return_pending_merchant',
                        'return_fee_quote' => (int) $delivery->return_fee,
                        'return_fee_applied' => $returnFee,
                        'return_fee_mode' => $feeMode,
                        'return_reason' => $returnReason,
                    ],
                ]);
            }

            // The order-value budget hold is no longer needed after a failed
            // delivery. This method is idempotent, so a repeat browser post
            // cannot release the same reservation twice.
            if ($feeMode === 'none') {
                $this->refundAdministrativeDeductionForFreeReturn($delivery, $actor);
            } else {
                $this->postReturnedAdministrativeDeduction($delivery);
            }
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
            $returnFeeMode = $delivery->return_fee_mode ?: ($returnFee > 0 ? 'fee' : 'none');
            $returnReason = trim((string) $delivery->return_reason);
            $confirmedAt = now();
            $confirmationNote = $note ?: 'تم تأكيد تسليم الطلب المرتجع إلى التاجر.';

            $delivery->forceFill([
                'workflow_stage' => 'returned_to_merchant',
                'returned_to_merchant_at' => $confirmedAt,
            ])->save();

            // The actual debit is posted once by
            // postReturnedAdministrativeDeduction() when the courier selects
            // a paid return. Do not write a second, ledger-only fee entry
            // here: it made the wallet history show two deductions while the
            // Qi balance changed only once.
            // A new order already carries a deduction snapshot from claim
            // time. Its optional return-fee choice is operational metadata,
            // not a second Qi debit. Only pre-policy orders can have a
            // separately posted return fee.
            $hasLegacyReturnCharge = $returnFee > 0 && $delivery->admin_deduction_applied === null;
            $feePosted = $hasLegacyReturnCharge && Transaction::withoutGlobalScope(TenantScope::class)
                ->where('order_id', $delivery->id)
                ->where('user_id', $actor->id)
                ->where('type', 'commission')
                ->where('direction', -1)
                ->exists();

            if ($hasLegacyReturnCharge) {
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
                    'return_fee_mode' => $returnFeeMode,
                    'return_reason' => $returnReason,
                    'fee_ledger_posted' => $feePosted,
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
                    'return_fee_mode' => $returnFeeMode,
                    'return_reason' => $returnReason,
                    'fee_posted' => $feePosted,
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
                    'body_ar' => $returnFeeMode === 'fee'
                        ? 'تم تأكيد إعادة الطلب إليك بأجرة التوصيل المعتمدة '.number_format($returnFee).' د.ع. السبب: '.$returnReason
                        : 'تم تأكيد إعادة الطلب إليك بدون أجرة توصيل. السبب: '.$returnReason,
                    'body_en' => $returnFeeMode === 'fee'
                        ? 'The return was confirmed with the quoted '.number_format($returnFee).' IQD delivery fee. Reason: '.$returnReason
                        : 'The return was confirmed with no delivery fee. Reason: '.$returnReason,
                    'body_ku' => $returnFeeMode === 'fee'
                        ? 'گەڕاندنەوەکە بە کرێی دیاریکراوی '.number_format($returnFee).' د.ع پشتڕاست کرایەوە. هۆکار: '.$returnReason
                        : 'گەڕاندنەوەکە بەبێ کرێی گواستنەوە پشتڕاست کرایەوە. هۆکار: '.$returnReason,
                    'data' => [
                        'order_id' => $delivery->id,
                        'status' => 'returned',
                        'stage' => 'returned_to_merchant',
                        'return_fee_quote' => (int) $delivery->return_fee,
                        'return_fee_applied' => $returnFee,
                        'return_fee_mode' => $returnFeeMode,
                        'return_reason' => $returnReason,
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

    /** Keep the customer-facing order lifecycle explicit and sequential. */
    private function ensureValidStatusTransition(Order $order, string $to, bool $allowApprovedReoffer = false): void
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

        // Re-offering is the sole internal recovery path back to the existing
        // pending state. The recovery service has already released the
        // current courier's holds and cleared the assignment before calling
        // here. Controllers never set this flag, so an admin note cannot
        // turn an accepted order back into a new offer arbitrarily.
        if ($allowApprovedReoffer && $order->status === 'approved' && $to === 'pending' && ! $order->courier_id) {
            return;
        }

        $this->ensure(false, 'الانتقال التشغيلي غير مسموح لهذه الحالة.');
    }

    protected function releaseCourierBudgetIfHeld(Order $order, string $status): void
    {
        // Prefer the regular courier assignment. The fallback keeps records
        // created by an older deployment releasable without reviving a
        // separate pickup/delivery workflow.
        $courierId = (int) ($order->courier_id ?: $order->delivery_courier_id);

        app(CourierBudgetHoldService::class)->releaseOutstandingForCourier(
            $order,
            $courierId,
            match ($status) {
                'delivered' => 'إعادة مبلغ حجز الطلب إلى رصيد الميزانية بعد التسليم',
                'cancelled' => 'إعادة ميزانية بعد إلغاء الطلب',
                'damaged' => 'إعادة ميزانية بعد تسجيل الطلب كتالف',
                'rejected' => 'إعادة ميزانية بعد رفض الطلب',
                default => 'إعادة مبلغ حجز الطلب إلى رصيد الميزانية بعد الإرجاع',
            },
        );
    }

    /**
     * Compatibility path for an already-approved order that predates the
     * acceptance-time reservation. New orders already have `paid_order` and
     * return immediately from this method.
     */
    protected function reserveCourierBudgetForPickup(Order $order): void
    {
        // New orders always use courier_id. The trailing values only close
        // records created by the retired multi-courier workflow.
        $courierId = $order->courier_id ?: $order->delivery_courier_id ?: $order->pickup_courier_id;
        if (! $courierId) {
            throw ValidationException::withMessages(['order' => ['يجب تعيين مندوب قبل استلام الطلب.']]);
        }

        $alreadyHeld = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $courierId)
            ->where('type', 'paid_order')
            ->where('direction', -1)
            ->exists();

        if ($alreadyHeld) {
            return;
        }

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $courierId],
            ['balance' => 0, 'budget' => 0, 'budget_balance' => 0],
        );
        $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
        $budgetHold = max(0, (int) $order->price);

        $this->ensure(
            (int) $wallet->budget_balance >= $budgetHold,
            'رصيد ميزانية المندوب لا يغطي سعر الطلب دون أجرة التوصيل.'
        );

        $wallet->decrement('budget_balance', $budgetHold);

        $courier = User::withoutGlobalScopes()->find($courierId);
        Transaction::create([
            'tenant_id' => $courier?->tenant_id ?? $order->tenant_id,
            'user_id' => $courierId,
            'type' => 'paid_order',
            'amount' => $budgetHold,
            'direction' => -1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => 'حجز سعر الطلب من رصيد الميزانية للطلب السابق عند الاستلام (لا يشمل أجرة التوصيل).',
        ]);
    }

    /**
     * Record a delivered COD order without mixing physical courier cash with
     * the courier's prepaid Qi credit:
     *
     * - the courier budget hold is released in full by the caller;
     * - the fixed administration deduction was already debited and frozen
     *   when the courier accepted the job; and
     * - the merchant receives the full order value because the courier pays
     *   it from the cash budget used when accepting the job.
     *
     * Every entry is guarded independently so retries cannot debit a wallet
     * or post a collection twice.
     */
    protected function postDeliveredSettlement(Order $order): void
    {
        $orderValue = max(0, (int) $order->price);
        $deliveryCharge = max(0, (int) $order->fee);
        // Prefer the sole current courier; retain a legacy fallback only for
        // settlement of historical records that predate this model.
        $courierId = $order->courier_id ?: $order->delivery_courier_id ?: $order->pickup_courier_id;

        if ($courierId) {
            $courier = User::withoutGlobalScopes()->find($courierId);

            if ($courier && $courier->isCourierRole()) {
                $alreadyCollected = Transaction::withoutGlobalScope(TenantScope::class)
                    ->where('order_id', $order->id)
                    ->where('user_id', $courier->id)
                    ->where('type', 'collected')
                    ->where('direction', 1)
                    ->exists();

                $companyFee = $order->admin_deduction_applied;

                // Preserve the accounting of an order that was already in
                // progress before acceptance-time deductions were deployed.
                // New orders always carry a non-null snapshot (including 0)
                // and therefore can never be charged a second time here.
                if ($companyFee === null) {
                    $companyFee = $this->postAdministrativeDeduction(
                        $order,
                        $courier,
                        'بعد تسليم طلب سابق',
                        $deliveryCharge,
                    );
                    $order->forceFill(['admin_deduction_applied' => $companyFee])->save();
                }

                $courierNetDeliveryFee = max(0, $deliveryCharge - (int) $companyFee);
                if (! $alreadyCollected && $courierNetDeliveryFee > 0) {
                    Transaction::create([
                        'tenant_id' => $courier->tenant_id ?? $order->tenant_id,
                        'user_id' => $courier->id,
                        'type' => 'collected',
                        'amount' => $courierNetDeliveryFee,
                        'direction' => 1,
                        'ref' => $order->track_no,
                        'order_id' => $order->id,
                        'date' => today(),
                        'note' => 'تحصيل أجرة التوصيل الصافي بعد استقطاع الإدارة عند تسليم الطلب.',
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
            ['balance' => 0, 'budget' => 0, 'budget_balance' => 0],
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

    /**
     * New orders are charged once when accepted, so returning a parcel must
     * never debit Qi a second time. The legacy branch keeps a pre-release
     * in-progress order financially consistent until it is closed.
     */
    protected function postReturnedAdministrativeDeduction(Order $order): void
    {
        if ($order->admin_deduction_applied !== null) {
            return;
        }

        if ((int) $order->return_fee_applied <= 0) {
            return;
        }

        $courierId = $order->courier_id ?: $order->delivery_courier_id ?: $order->pickup_courier_id;
        if (! $courierId) {
            return;
        }

        $courier = User::withoutGlobalScopes()->find($courierId);
        if ($courier && $courier->isCourierRole()) {
            $this->postAdministrativeDeduction(
                $order,
                $courier,
                'بعد إرجاع طلب سابق',
                max(0, (int) $order->return_fee_applied),
            );
        }
    }

    /**
     * A no-fee return is the one exception to the acceptance-time company
     * deduction: the courier gets that exact frozen amount back. The ledger
     * guards make a repeat HTTP request harmless and leave the original
     * snapshot intact for audit purposes.
     */
    protected function refundAdministrativeDeductionForFreeReturn(Order $order, User $courier): void
    {
        $courierId = (int) ($order->courier_id ?: $order->delivery_courier_id ?: $order->pickup_courier_id);
        if ($courierId <= 0 || $courierId !== (int) $courier->id) {
            return;
        }

        // The ledger is the source of truth for the amount actually taken
        // from Qi.  Newly accepted orders carry admin_deduction_applied, but
        // older in-progress orders can have a commission row without that
        // newer snapshot.  A free return must restore the real debit in both
        // cases rather than leaving a legacy courier charged by mistake.
        $chargedAmount = max(0, (int) Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $courierId)
            ->where('type', 'commission')
            ->where('direction', -1)
            ->sum('amount'));
        $refundedAmount = max(0, (int) Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $courierId)
            ->where('type', 'commission_refund')
            ->where('direction', 1)
            ->sum('amount'));
        $amount = max(0, $chargedAmount - $refundedAmount);

        if ($amount === 0) {
            return;
        }

        $wallet = Wallet::query()
            ->where('user_id', $courierId)
            ->lockForUpdate()
            ->first();
        if (! $wallet) {
            return;
        }

        $wallet->increment('balance', $amount);

        $refund = Transaction::create([
            'tenant_id' => $courier->tenant_id ?? $order->tenant_id,
            'user_id' => $courierId,
            'type' => 'commission_refund',
            'amount' => $amount,
            'direction' => 1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => 'إعادة استقطاع الإدارة بسبب إرجاع الطلب بدون أجرة توصيل.',
        ]);

        ActivityLog::create([
            'tenant_id' => $courier->tenant_id ?? $order->tenant_id,
            'user_id' => $courierId,
            'action' => 'wallet.commission_refunded_for_free_return',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'data' => [
                'amount' => $amount,
                'transaction_id' => $refund->id,
                'track_no' => $order->track_no,
            ],
            'ip' => request()->ip(),
        ]);

        Notification::create([
            'tenant_id' => $courier->tenant_id ?? $order->tenant_id,
            'user_id' => $courierId,
            'type' => 'finance',
            'title_ar' => 'إعادة استقطاع الإدارة',
            'title_en' => 'Platform deduction refunded',
            'title_ku' => 'گەڕاندنەوەی کەمکردنەوەی بەڕێوەبەرایەتی',
            'body_ar' => 'أُعيد مبلغ '.number_format($amount).' د.ع إلى رصيد Qi لأن الطلب '.$order->track_no.' أُرجع بدون أجرة توصيل.',
            'body_en' => number_format($amount).' IQD was returned to your Qi balance because '.$order->track_no.' was returned without a delivery fee.',
            'body_ku' => number_format($amount).' د.ع گەڕێندرایەوە بۆ باڵانسی Qi ـت چونکە '.$order->track_no.' بەبێ کرێی گواستنەوە گەڕێندرایەوە.',
            'data' => ['order_id' => $order->id, 'type' => 'commission_refund', 'amount' => $amount],
        ]);
    }

    /**
     * Compatibility charge for orders that were already underway before
     * `admin_deduction_applied` began being snapshotted at acceptance. New
     * orders use CourierOrderAssignmentService and never call this method.
     */
    protected function postAdministrativeDeduction(Order $order, User $courier, string $when, int $amount): int
    {
        $amount = max(0, $amount);

        if ($amount === 0) {
            return 0;
        }

        $posted = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $courier->id)
            ->where('type', 'commission')
            ->where('direction', -1)
            ->exists();

        if ($posted) {
            return $amount;
        }

        Wallet::firstOrCreate(['user_id' => $courier->id], ['balance' => 0, 'budget' => 0, 'budget_balance' => 0]);
        $wallet = Wallet::query()->where('user_id', $courier->id)->lockForUpdate()->firstOrFail();
        $this->ensure((int) $wallet->balance >= $amount, 'رصيد المندوب لا يغطي مبلغ استقطاع الإدارة لهذا الطلب.');
        $wallet->decrement('balance', $amount);

        Transaction::create([
            'tenant_id' => $courier->tenant_id ?? $order->tenant_id,
            'user_id' => $courier->id,
            'type' => 'commission',
            'amount' => $amount,
            'direction' => -1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => 'استقطاع الإدارة '.$when.'.',
        ]);

        Notification::create([
            'tenant_id' => $courier->tenant_id,
            'user_id' => $courier->id,
            'type' => 'finance',
            'title_ar' => 'استقطاع الإدارة',
            'title_en' => 'Platform deduction',
            'title_ku' => 'کەمکردنەوەی بەڕێوەبەرایەتی',
            'body_ar' => 'تم استقطاع '.number_format($amount).' د.ع من رصيد Qi للطلب '.$order->track_no.'.',
            'body_en' => number_format($amount).' IQD was deducted from your Qi balance for '.$order->track_no.'.',
            'body_ku' => number_format($amount).' IQD was deducted from your Qi balance for '.$order->track_no.'.',
            'data' => ['order_id' => $order->id, 'type' => 'commission', 'amount' => $amount],
        ]);

        return $amount;
    }
}
