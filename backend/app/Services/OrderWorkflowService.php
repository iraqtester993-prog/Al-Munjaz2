<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\OrderStatusLog;
use App\Models\User;
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
    ];

    public function changeStatus(Order $order, string $status, User $actor, ?string $note = null): void
    {
        if (! in_array($status, Order::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'حالة الطلب غير صحيحة.']);
        }

        DB::transaction(function () use ($order, $status, $actor, $note) {
            $order->refresh();
            $fromStatus = $order->status;
            $fromStage = $order->workflow_stage;

            if ($fromStatus === $status) {
                return;
            }

            $updates = [
                'status' => $status,
                'workflow_stage' => self::STATUS_TO_STAGE[$status] ?? $order->workflow_stage,
            ];

            if ($status === 'approved') {
                $updates['accepted_at'] = now();
            }
            if ($status === 'courier') {
                $updates['picked_at'] = now();
                $updates['courier_id'] = $order->courier_id ?: $actor->id;
                $updates['delivery_courier_id'] = $order->delivery_courier_id ?: $updates['courier_id'];
            }
            if ($status === 'delivered') {
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
        });
    }
}
