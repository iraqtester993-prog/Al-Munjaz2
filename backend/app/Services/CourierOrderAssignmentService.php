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
            $this->ensure(
                $courier->isCourierVerified(),
                'حساب المندوب بانتظار توثيق الإدارة قبل استلام الطلبات.'
            );
            $this->ensure(
                ! $requireOnDuty || $courier->is_online,
                __('You cannot accept this order while you are unavailable. Turn on “Available for Work” from the home page, then try again.')
            );

            // A self-claimed delivery is an operational action by the
            // courier, unlike a dashboard assignment by an administrator.
            // Check the freshly locked courier record so a stale browser
            // state cannot claim a job after location sharing has stopped.
            if ($requireOnDuty) {
                app(CourierLocationService::class)->requireFreshOperationalLocation($courier);
            }

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
                    'budget_balance' => 0,
                ]);
            }

            // A claim is the financial commitment for this job.  The
            // declared budget remains a stable ceiling; only its available
            // balance is reserved, and delivery pricing must never consume
            // the cash used to pay the merchant for the product itself.
            $budgetHold = max(0, (int) $delivery->price);
            $adminDeduction = max(0, (int) $courier->admin_deduction_per_order);
            // A courier-specific value takes priority. The global setting is
            // the administrator-managed default for couriers that have not
            // been given an individual deduction yet; delivery_fee is never
            // used as an implicit fallback.
            if ($adminDeduction === 0) {
                $adminDeduction = max(0, min((int) $this->settingForOrder($delivery, 'admin_deduction_fee', 0), 1_000_000));
            }

            $this->ensure(
                (int) $wallet->budget_balance >= $budgetHold,
                'رصيد ميزانية المندوب لا يغطي سعر الطلب دون أجرة التوصيل.'
            );
            $this->ensure(
                (int) $wallet->balance >= $adminDeduction,
                'رصيد Qi المتاح لا يغطي مبلغ استقطاع الإدارة لهذا الطلب.'
            );

            if ($budgetHold > 0) {
                $wallet->decrement('budget_balance', $budgetHold);

                Transaction::create([
                    'tenant_id' => $courier->tenant_id ?? $delivery->tenant_id,
                    'user_id' => $courier->id,
                    'type' => 'paid_order',
                    'amount' => $budgetHold,
                    'direction' => -1,
                    'ref' => $delivery->track_no,
                    'order_id' => $delivery->id,
                    'date' => today(),
                    'note' => 'حجز سعر الطلب من رصيد الميزانية عند قبول الطلب (لا يشمل أجرة التوصيل).',
                ]);
            }

            if ($adminDeduction > 0) {
                $wallet->decrement('balance', $adminDeduction);

                Transaction::create([
                    'tenant_id' => $courier->tenant_id ?? $delivery->tenant_id,
                    'user_id' => $courier->id,
                    'type' => 'commission',
                    'amount' => $adminDeduction,
                    'direction' => -1,
                    'ref' => $delivery->track_no,
                    'order_id' => $delivery->id,
                    'date' => today(),
                    'note' => 'استقطاع الإدارة الثابت عند قبول الطلب.',
                ]);

                Notification::create([
                    'tenant_id' => $courier->tenant_id,
                    'user_id' => $courier->id,
                    'type' => 'finance',
                    'title_ar' => 'استقطاع الإدارة',
                    'title_en' => 'Platform deduction',
                    'title_ku' => 'کەمکردنەوەی بەڕێوەبەرایەتی',
                    'body_ar' => 'تم استقطاع '.number_format($adminDeduction).' د.ع من رصيد Qi عند قبول الطلب '.$delivery->track_no.'.',
                    'body_en' => number_format($adminDeduction).' IQD was deducted from your Qi balance when accepting '.$delivery->track_no.'.',
                    'body_ku' => number_format($adminDeduction).' د.ع لە باڵانسی Qi ـت کەم کرایەوە لە کاتی وەرگرتنی '.$delivery->track_no.'.',
                    'data' => ['order_id' => $delivery->id, 'type' => 'commission', 'amount' => $adminDeduction],
                ]);
            }

            // A pending order has an availability deadline. Once assigned,
            // the same field becomes the expected pickup deadline configured
            // by the administrator.
            $pickupMinutes = max(5, min((int) $this->settingForOrder($delivery, 'pickup_eta_minutes', 30), 240));
            $delivery->update([
                'courier_id' => $courier->id,
                'pickup_deadline_at' => now()->addMinutes($pickupMinutes),
                // Immutable snapshot: later edits to the courier's profile
                // cannot alter the net delivery earning for this order.
                'admin_deduction_applied' => $adminDeduction,
            ]);
            app(OrderWorkflowService::class)->changeStatus($delivery, 'approved', $actor, $note);

            Notification::create([
                'tenant_id' => $courier->tenant_id,
                'user_id' => $courier->id,
                'type' => 'order',
                'title_ar' => 'طلب جديد: '.$delivery->track_no,
                'title_en' => 'New delivery: '.$delivery->track_no,
                'title_ku' => 'داواکارییەکی نوێ: '.$delivery->track_no,
                'body_ar' => 'تم إسناد الطلب إليك. الوصول المتوقع إلى التاجر خلال '.$pickupMinutes.' دقيقة.',
                'body_en' => 'This order was assigned to you. Expected merchant pickup is within '.$pickupMinutes.' minutes.',
                'body_ku' => 'ئەم داواکارییە بۆ تۆ دیاری کرا. کاتی چاوەڕێکراوی گەیشتن بە بازرگان '.$pickupMinutes.' خولەکە.',
                'data' => ['order_id' => $delivery->id, 'status' => 'approved'],
            ]);
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['order' => [$message]]);
        }
    }

    private function settingForOrder(Order $order, string $key, mixed $default): mixed
    {
        $branchId = (int) ($order->branch_id ?: $order->origin_branch_id);

        return app(BranchSettingsResolver::class)
            ->getForOperationalBranch($branchId > 0 ? $branchId : null, $key, $default);
    }
}
