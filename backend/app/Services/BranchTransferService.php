<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns the physical branch-transfer lifecycle.
 *
 * A transfer deliberately belongs to exactly one merchant tenant. Platform
 * branches may be endpoints, but combining orders from different merchants
 * in one manifest would expose data and make its audit trail ambiguous.
 */
class BranchTransferService
{
    /**
     * @param array{
     *     origin_branch_id:int,
     *     destination_branch_id:int,
     *     transporter_id:int,
     *     order_ids:array<int, int|string>,
     *     notes?:string|null
     * } $data
     */
    public function create(User $actor, array $data): BranchTransfer
    {
        return DB::transaction(function () use ($actor, $data): BranchTransfer {
            $orderIds = collect($data['order_ids'])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values();

            $this->ensure($orderIds->isNotEmpty(), 'اختر طلباً واحداً على الأقل للنقل.');
            $this->ensure($orderIds->unique()->count() === $orderIds->count(), 'لا يمكن تكرار الطلب داخل التحويل نفسه.');

            $origin = $this->branchForUpdate((int) $data['origin_branch_id']);
            $destination = $this->branchForUpdate((int) $data['destination_branch_id']);
            $this->ensure($origin->id !== $destination->id, 'يجب أن يكون فرع الاستلام مختلفاً عن فرع الإرسال.');
            $this->ensure($origin->is_active && $destination->is_active, 'لا يمكن النقل من أو إلى فرع غير نشط.');

            $orders = Order::withoutGlobalScope(TenantScope::class)
                ->whereIn('id', $orderIds->all())
                ->lockForUpdate()
                ->get();

            $this->ensure($orders->count() === $orderIds->count(), 'يوجد طلب غير صالح أو لم يعد متاحاً للنقل.');

            $tenantId = (int) $orders->first()->tenant_id;
            $this->ensure(
                $orders->every(fn (Order $order) => (int) $order->tenant_id === $tenantId),
                'لا يمكن جمع طلبات تجار مختلفين في تحويل واحد.'
            );
            $this->ensure(
                $origin->canServeTenant($tenantId) && $destination->canServeTenant($tenantId),
                'مسار الفروع لا ينتمي إلى شبكة الإدارة أو إلى التاجر صاحب الطلب.'
            );

            $this->ensureTransferableOrders($orders, $origin->id, $destination->id);
            $this->ensureOrdersHaveNoActiveTransfer($orderIds->all());

            $transporter = $this->activeTransporter((int) $data['transporter_id']);

            $transfer = BranchTransfer::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenantId,
                'origin_branch_id' => $origin->id,
                'destination_branch_id' => $destination->id,
                'transporter_id' => $transporter->id,
                'reference' => $this->nextReference(),
                'status' => BranchTransfer::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $transfer->orders()->sync($orderIds->all());

            $this->record($actor, $transfer, 'branch_transfer.created', [
                'order_ids' => $orderIds->all(),
                'origin_branch_id' => $origin->id,
                'destination_branch_id' => $destination->id,
                'transporter_id' => $transporter->id,
                'status' => BranchTransfer::DRAFT,
            ]);

            return $transfer;
        });
    }

    public function dispatch(int $transferId, User $actor): BranchTransfer
    {
        return DB::transaction(function () use ($transferId, $actor): BranchTransfer {
            $transfer = $this->transferForUpdate($transferId);
            $this->ensure($transfer->status === BranchTransfer::DRAFT, 'يمكن إرسال التحويلات الموجودة في المسودة فقط.');

            $origin = $this->branchForUpdate((int) $transfer->origin_branch_id);
            $destination = $this->branchForUpdate((int) $transfer->destination_branch_id);
            $this->ensure($origin->is_active && $destination->is_active, 'لا يمكن إرسال تحويل مرتبط بفرع غير نشط.');

            $transporter = $this->activeTransporter($transfer->transporter_id);
            $orders = $this->transferOrdersForUpdate($transfer);
            $this->ensure($orders->isNotEmpty(), 'لا يمكن إرسال تحويل بلا طلبات.');
            $this->ensureTransferTenantAndRoute($transfer, $orders, $origin, $destination);
            $this->ensureTransferableOrders($orders, $origin->id, $destination->id);
            $this->ensureOrdersHaveNoActiveTransfer($orders->pluck('id')->all(), $transfer->id);

            $dispatchedAt = now();
            $transfer->update([
                'status' => BranchTransfer::DISPATCHED,
                'dispatched_at' => $dispatchedAt,
            ]);

            foreach ($orders as $order) {
                $order->update([
                    // `branch_id` is the last physically confirmed branch
                    // while the transfer stage tells clients it is in transit.
                    'branch_id' => $origin->id,
                    'workflow_stage' => 'in_transfer',
                ]);

                $this->movement($order, $origin->id, $destination->id, $actor, 'in_transfer', $transfer, 'تم إرسال الطلب ضمن تحويل بين الفروع.');
                $this->notifyMerchant($order, $transfer, 'dispatched');
            }

            $this->notifyTransporter($transporter, $transfer, 'dispatched', $orders->count());

            $this->record($actor, $transfer, 'branch_transfer.dispatched', [
                'order_ids' => $orders->pluck('id')->all(),
                'dispatched_at' => $dispatchedAt->toIso8601String(),
                'transporter_id' => $transporter->id,
            ]);

            return $transfer;
        });
    }

    public function receive(int $transferId, User $actor): BranchTransfer
    {
        return DB::transaction(function () use ($transferId, $actor): BranchTransfer {
            $transfer = $this->transferForUpdate($transferId);
            $this->ensure($transfer->status === BranchTransfer::DISPATCHED, 'يمكن استلام التحويلات المرسلة فقط.');

            $origin = $this->branchForUpdate((int) $transfer->origin_branch_id);
            $destination = $this->branchForUpdate((int) $transfer->destination_branch_id);
            $this->ensure($destination->is_active, 'لا يمكن استلام التحويل في فرع غير نشط.');

            $orders = $this->transferOrdersForUpdate($transfer);
            $this->ensure($orders->isNotEmpty(), 'لا يمكن استلام تحويل بلا طلبات.');
            $this->ensureTransferTenantAndRoute($transfer, $orders, $origin, $destination);
            $this->ensure(
                $orders->every(fn (Order $order) => $order->workflow_stage === 'in_transfer'),
                'لا يمكن استلام التحويل قبل أن تكون جميع طلباته في مرحلة النقل.'
            );

            $receivedAt = now();
            $transfer->update([
                'status' => BranchTransfer::RECEIVED,
                'received_at' => $receivedAt,
            ]);

            foreach ($orders as $order) {
                $order->update([
                    'branch_id' => $destination->id,
                    'workflow_stage' => 'at_destination_branch',
                ]);

                $this->movement($order, $origin->id, $destination->id, $actor, 'at_destination_branch', $transfer, 'تم استلام الطلب في فرع الوجهة.');
                $this->notifyMerchant($order, $transfer, 'received');
            }

            if ($transfer->transporter_id) {
                $transporter = User::withoutGlobalScopes()->find($transfer->transporter_id);
                if ($transporter) {
                    $this->notifyTransporter($transporter, $transfer, 'received', $orders->count());
                }
            }

            $this->record($actor, $transfer, 'branch_transfer.received', [
                'order_ids' => $orders->pluck('id')->all(),
                'received_at' => $receivedAt->toIso8601String(),
            ]);

            return $transfer;
        });
    }

    private function transferForUpdate(int $transferId): BranchTransfer
    {
        return BranchTransfer::withoutGlobalScope(TenantScope::class)
            ->lockForUpdate()
            ->findOrFail($transferId);
    }

    private function branchForUpdate(int $branchId): Branch
    {
        return Branch::withoutGlobalScope(TenantScope::class)
            ->lockForUpdate()
            ->findOrFail($branchId);
    }

    private function activeTransporter(?int $transporterId): User
    {
        $this->ensure((bool) $transporterId, 'يجب تعيين ناقل نشط للتحويل.');

        $transporter = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($transporterId);
        $this->ensure(
            $transporter->role === 'transporter' && $transporter->status === 'active',
            'المستخدم المختار ليس ناقلاً نشطاً.'
        );

        return $transporter;
    }

    /** @return Collection<int, Order> */
    private function transferOrdersForUpdate(BranchTransfer $transfer): Collection
    {
        $orderIds = DB::table('branch_transfer_orders')
            ->where('branch_transfer_id', $transfer->id)
            ->orderBy('order_id')
            ->pluck('order_id')
            ->map(fn ($id) => (int) $id);

        $orders = Order::withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $orderIds->all())
            ->lockForUpdate()
            ->get();

        $this->ensure(
            $orders->count() === $orderIds->count(),
            'أحد طلبات التحويل لم يعد متاحاً.'
        );

        return $orders;
    }

    /** @param Collection<int, Order> $orders */
    private function ensureTransferTenantAndRoute(BranchTransfer $transfer, Collection $orders, Branch $origin, Branch $destination): void
    {
        $this->ensure(
            $orders->every(fn (Order $order) => (int) $order->tenant_id === (int) $transfer->tenant_id),
            'تحتوي قائمة التحويل على طلب من حساب مختلف.'
        );
        $this->ensure(
            $origin->canServeTenant((int) $transfer->tenant_id) && $destination->canServeTenant((int) $transfer->tenant_id),
            'لم يعد مسار الفروع متاحاً لصاحب التحويل.'
        );
        $this->ensure(
            $origin->id !== $destination->id,
            'مسار التحويل غير صالح.'
        );
        $this->ensure(
            $orders->every(fn (Order $order) => (int) $order->origin_branch_id === $origin->id
                && (int) $order->destination_branch_id === $destination->id
            ),
            'تغيّر مسار أحد الطلبات ولا يمكن إكمال التحويل.'
        );
    }

    /** @param Collection<int, Order> $orders */
    private function ensureTransferableOrders(Collection $orders, int $originBranchId, int $destinationBranchId): void
    {
        $this->ensure(
            $orders->every(fn (Order $order) => $order->workflow_stage === 'awaiting_transfer'
                && (int) $order->origin_branch_id === $originBranchId
                && (int) $order->destination_branch_id === $destinationBranchId
                && ! in_array($order->status, ['delivered', 'returned', 'cancelled', 'damaged'], true)
            ),
            'كل طلب في التحويل يجب أن يكون بانتظار النقل وعلى المسار نفسه.'
        );
    }

    /** @param array<int, int> $orderIds */
    private function ensureOrdersHaveNoActiveTransfer(array $orderIds, ?int $exceptTransferId = null): void
    {
        $query = DB::table('branch_transfer_orders as transfer_orders')
            ->join('branch_transfers as transfers', 'transfers.id', '=', 'transfer_orders.branch_transfer_id')
            ->whereIn('transfer_orders.order_id', $orderIds)
            ->whereIn('transfers.status', [BranchTransfer::DRAFT, BranchTransfer::DISPATCHED]);

        if ($exceptTransferId) {
            $query->where('transfers.id', '!=', $exceptTransferId);
        }

        $this->ensure(! $query->exists(), 'أحد الطلبات مرتبط بتحويل نشط آخر.');
    }

    private function movement(Order $order, int $originBranchId, int $destinationBranchId, User $actor, string $stage, BranchTransfer $transfer, string $note): void
    {
        OrderMovement::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'from_branch_id' => $originBranchId,
            'to_branch_id' => $destinationBranchId,
            'actor_id' => $actor->id,
            'stage' => $stage,
            'note' => $note,
            'meta' => [
                'transfer_id' => $transfer->id,
                'transfer_reference' => $transfer->reference,
                'transfer_status' => $transfer->status,
            ],
            'occurred_at' => now(),
        ]);
    }

    private function notifyMerchant(Order $order, BranchTransfer $transfer, string $event): void
    {
        if (! $order->merchant_id) {
            return;
        }

        $isDispatch = $event === 'dispatched';
        Notification::withoutGlobalScopes()->create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $order->merchant_id,
            'type' => 'order',
            'title_ar' => 'تحديث نقل الطلب: '.$order->track_no,
            'title_en' => 'Order transfer update: '.$order->track_no,
            'title_ku' => 'نوێکردنەوەی گواستنەوەی داواکاری: '.$order->track_no,
            'body_ar' => $isDispatch
                ? 'غادر الطلب فرع الاستلام وهو في الطريق إلى فرع الوجهة.'
                : 'تم استلام الطلب في فرع الوجهة وهو جاهز للمرحلة التالية.',
            'body_en' => $isDispatch
                ? 'The order left the origin branch and is moving to the destination branch.'
                : 'The order was received at the destination branch and is ready for the next stage.',
            'body_ku' => $isDispatch
                ? 'داواکارییەکە لە لقەی سەرەتا دەرچووە و بەرەو لقەی مەبەست دەچێت.'
                : 'داواکارییەکە لە لقەی مەبەست وەرگیراوە و بۆ قۆناغی داهاتوو ئامادەیە.',
            'data' => [
                'order_id' => $order->id,
                'transfer_id' => $transfer->id,
                'transfer_reference' => $transfer->reference,
                'stage' => $isDispatch ? 'in_transfer' : 'at_destination_branch',
                'url' => '/app/orders',
            ],
            'dedup_key' => "transfer:{$transfer->id}:{$event}:merchant:{$order->id}",
        ]);
    }

    private function notifyTransporter(User $transporter, BranchTransfer $transfer, string $event, int $ordersCount): void
    {
        $isDispatch = $event === 'dispatched';
        Notification::withoutGlobalScopes()->create([
            'tenant_id' => $transporter->tenant_id,
            'user_id' => $transporter->id,
            'type' => 'order',
            'title_ar' => $isDispatch ? 'تحويل جاهز للنقل' : 'تم استلام التحويل',
            'title_en' => $isDispatch ? 'Transfer ready for transport' : 'Transfer received',
            'title_ku' => $isDispatch ? 'گواستنەوەکە بۆ گواستنەوە ئامادەیە' : 'گواستنەوەکە وەرگیرا',
            'body_ar' => $isDispatch
                ? "التحويل {$transfer->reference} يتضمن {$ordersCount} طلب/طلبات."
                : "تم استلام التحويل {$transfer->reference} في فرع الوجهة.",
            'body_en' => $isDispatch
                ? "Transfer {$transfer->reference} contains {$ordersCount} order(s)."
                : "Transfer {$transfer->reference} was received at the destination branch.",
            'body_ku' => $isDispatch
                ? "گواستنەوەی {$transfer->reference} {$ordersCount} داواکاری لەخۆدەگرێت."
                : "گواستنەوەی {$transfer->reference} لە لقەی مەبەست وەرگیرا.",
            'data' => [
                'transfer_id' => $transfer->id,
                'transfer_reference' => $transfer->reference,
                'status' => $event,
            ],
            'dedup_key' => "transfer:{$transfer->id}:{$event}:transporter:{$transporter->id}",
        ]);
    }

    /** @param array<string, mixed> $data */
    private function record(User $actor, BranchTransfer $transfer, string $action, array $data): void
    {
        ActivityLog::create([
            'tenant_id' => $transfer->tenant_id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => 'branch_transfer',
            'subject_id' => $transfer->id,
            'data' => $data + ['reference' => $transfer->reference],
            'ip' => request()->ip(),
        ]);
    }

    private function nextReference(): string
    {
        do {
            $reference = 'TRF-'.Str::upper(Str::random(12));
        } while (BranchTransfer::withoutGlobalScope(TenantScope::class)->where('reference', $reference)->exists());

        return $reference;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['transfer' => [$message]]);
        }
    }
}
