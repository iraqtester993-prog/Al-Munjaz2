<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only operational assignment for one order.
 *
 * A direct order belongs to exactly one general courier. The same courier
 * receives the parcel and delivers it, through CourierOrderAssignmentService,
 * so the budget, deduction, and status trail stay attached to one account.
 * Legacy specialist columns are intentionally not changed here; they remain
 * historical data only and are never populated by new assignments.
 */
class OrderOperationalAssignmentService
{
    /** @var array<int, string> */
    public const ASSIGNMENT_ROLES = ['courier'];

    /** @return array<int, string> */
    public function modesFor(User $user): array
    {
        return $user->role === 'courier' ? self::ASSIGNMENT_ROLES : [];
    }

    public function assign(
        Order $order,
        User $assignee,
        User $actor,
        ?string $assignmentRole = null,
        string $note = 'تم تعيين المندوب من لوحة الإدارة.',
    ): void {
        $assignmentRole ??= 'courier';

        $this->ensure(
            in_array($assignmentRole, $this->modesFor($assignee), true),
            'الدور المختار لا يطابق صلاحية المندوب المحدد.'
        );
        $this->ensure($assignee->status === 'active', 'المستخدم المختار ليس مندوباً نشطاً.');
        $this->ensure(
            $assignee->isCourierVerified(),
            'حساب المندوب بانتظار توثيق الإدارة قبل استلام الطلبات.'
        );
        $this->ensure($order->province_id !== null, 'يجب تحديد محافظة الطلب قبل تعيين المندوب.');
        $this->ensure(
            app(CourierOrderAccess::class)->canServeProvince($assignee, (int) $order->province_id),
            'هذا المندوب غير مفعّل في محافظة الطلب.'
        );

        $this->ensure($order->status === 'pending' && $order->courier_id === null, 'الطلب لم يعد متاحاً لتعيين مندوب واحد.');

        app(CourierOrderAssignmentService::class)->assign($order, $assignee, $actor, $note);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['courier_id' => [$message]]);
        }
    }
}
