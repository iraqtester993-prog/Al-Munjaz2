<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only operational assignment for one order.
 *
 * General courier assignment continues through CourierOrderAssignmentService
 * so its existing budget rules remain intact. Pickup and delivery specialists
 * are planned into their own explicit slots and never create a fictitious
 * wallet movement. A transporter belongs to a branch transfer, not directly
 * to an order, so it is intentionally excluded from this service.
 */
class OrderOperationalAssignmentService
{
    /** @var array<int, string> */
    public const ASSIGNMENT_ROLES = ['courier', 'pickup_courier', 'delivery_courier'];

    /** @return array<int, string> */
    public function modesFor(User $user): array
    {
        return match ($user->role) {
            'courier' => self::ASSIGNMENT_ROLES,
            'pickup_courier' => ['pickup_courier'],
            'delivery_courier' => ['delivery_courier'],
            default => [],
        };
    }

    public function assign(
        Order $order,
        User $assignee,
        User $actor,
        ?string $assignmentRole = null,
        string $note = 'تم تعيين المندوب من لوحة الإدارة.',
    ): void {
        $assignmentRole ??= $assignee->role === 'courier' ? 'courier' : $assignee->role;

        $this->ensure(
            in_array($assignmentRole, $this->modesFor($assignee), true),
            'الدور المختار لا يطابق صلاحية المندوب المحدد.'
        );
        $this->ensure($assignee->status === 'active', 'المستخدم المختار ليس مندوباً نشطاً.');
        $this->ensure($order->province_id !== null, 'يجب تحديد محافظة الطلب قبل تعيين المندوب.');
        $this->ensure(
            app(CourierOrderAccess::class)->canServeProvince($assignee, (int) $order->province_id),
            'هذا المندوب غير مفعّل في محافظة الطلب.'
        );

        if ($assignmentRole === 'courier') {
            $this->ensure($order->status === 'pending' && $order->courier_id === null, 'الطلب لم يعد متاحاً للتعيين العام.');

            app(CourierOrderAssignmentService::class)->assign($order, $assignee, $actor, $note);

            return;
        }

        $this->assignSpecialist($order, $assignee, $actor, $assignmentRole, $note);
    }

    private function assignSpecialist(
        Order $order,
        User $assignee,
        User $actor,
        string $assignmentRole,
        string $note,
    ): void {
        DB::transaction(function () use ($order, $assignee, $actor, $assignmentRole, $note): void {
            $order = Order::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->ensure(! Order::isTerminalStatus($order->status), 'لا يمكن تعيين مندوب لطلب مكتمل أو مغلق.');

            $column = $assignmentRole === 'pickup_courier'
                ? 'pickup_courier_id'
                : 'delivery_courier_id';
            $stage = $assignmentRole === 'pickup_courier'
                ? 'pickup_assigned'
                : 'delivery_assigned';

            $order->update([$column => $assignee->id]);

            OrderMovement::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'from_branch_id' => $order->origin_branch_id,
                'to_branch_id' => $order->destination_branch_id,
                'actor_id' => $actor->id,
                'stage' => $stage,
                'note' => $note,
                'meta' => [
                    'event' => 'courier_assignment',
                    'assignment_role' => $assignmentRole,
                    'assignee_id' => $assignee->id,
                    'assignee_name' => $assignee->name,
                    'assignee_role' => $assignee->role,
                ],
                'occurred_at' => now(),
            ]);

            ActivityLog::create([
                'tenant_id' => $order->tenant_id,
                'user_id' => $actor->id,
                'action' => 'order.courier_assigned',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'data' => [
                    'assignment_role' => $assignmentRole,
                    'assignee_id' => $assignee->id,
                ],
                'ip' => request()->ip(),
            ]);

            Notification::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $assignee->tenant_id,
                'user_id' => $assignee->id,
                'type' => 'order',
                'title_ar' => 'تعيين تشغيلي: '.$order->track_no,
                'title_en' => 'Operational assignment: '.$order->track_no,
                'title_ku' => 'دیاریکردنی کارپێکردن: '.$order->track_no,
                'body_ar' => $assignmentRole === 'pickup_courier'
                    ? 'تم تعيينك لاستلام الطلب.'
                    : 'تم تعيينك لتوصيل الطلب.',
                'body_en' => $assignmentRole === 'pickup_courier'
                    ? 'You were assigned to pick up this order.'
                    : 'You were assigned to deliver this order.',
                'body_ku' => $assignmentRole === 'pickup_courier'
                    ? 'بۆ وەرگرتنی ئەم داواکارییە دیاری کرایت.'
                    : 'بۆ گەیاندنی ئەم داواکارییە دیاری کرایت.',
                'data' => [
                    'order_id' => $order->id,
                    'assignment_role' => $assignmentRole,
                    'workflow_stage' => $stage,
                ],
            ]);
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['courier_id' => [$message]]);
        }
    }
}
