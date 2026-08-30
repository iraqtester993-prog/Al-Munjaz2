<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Notification;
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
                ]);
            }

            $this->ensure($wallet->budget >= $delivery->price, 'ميزانية المندوب أقل من قيمة الطلب.');

            // The courier's Qi balance pays the platform fee only when the
            // order is delivered, but checking the quoted fee before the
            // job is accepted prevents a courier from completing work that
            // cannot be settled later.
            $companyFee = min(max(0, (int) $delivery->price), max(0, (int) $delivery->fee));
            $this->ensure(
                (int) $wallet->balance >= $companyFee,
                'رصيد المندوب لا يغطي رسوم الشركة لهذا الطلب.'
            );

            // A pending order has an availability deadline. Once assigned,
            // the same field becomes the expected pickup deadline configured
            // by the administrator.
            $pickupMinutes = max(5, min((int) Setting::get('pickup_eta_minutes', 30), 240));
            $delivery->update([
                'courier_id' => $courier->id,
                'pickup_deadline_at' => now()->addMinutes($pickupMinutes),
            ]);
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
}
