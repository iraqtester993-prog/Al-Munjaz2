<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\FinanceRequest;
use App\Models\Notification;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns every balance-changing finance operation.
 *
 * The public app only creates a pending request.  The dashboard applies it in
 * one database transaction after an administrator has confirmed the physical
 * settlement.  This keeps wallet and branch cashbox values reproducible from
 * the ledger and prevents a courier from crediting themselves.
 */
class FinanceRequestService
{
    public function submit(User $user, string $type, int $amount, ?int $branchId = null, ?string $note = null): FinanceRequest
    {
        $this->ensure(in_array($type, FinanceRequest::TYPES, true), 'نوع العملية المالية غير صالح.');
        $this->ensure($amount >= 1000, 'الحد الأدنى للعملية هو 1,000 د.ع.');

        if (in_array($type, [FinanceRequest::CASH_HANDOVER, FinanceRequest::BUDGET_RECHARGE], true)) {
            $this->ensure($user->isCourierRole(), 'هذه العملية متاحة للمندوب فقط.');
        }

        if ($type === FinanceRequest::MERCHANT_PAYOUT) {
            $this->ensure($user->role === 'merchant', 'طلب التسوية متاح للتاجر فقط.');
        }

        if ($branchId) {
            $this->activeBranch($branchId);
        }

        if ($type === FinanceRequest::CASH_HANDOVER) {
            $pending = (int) FinanceRequest::query()
                ->where('user_id', $user->id)
                ->where('type', FinanceRequest::CASH_HANDOVER)
                ->where('status', FinanceRequest::PENDING)
                ->sum('amount');
            $this->ensure(
                $amount <= max(0, $this->cashOnHand($user->id) - $pending),
                'المبلغ المطلوب أكبر من النقدية المتاحة للتسليم.'
            );
        }

        if ($type === FinanceRequest::MERCHANT_PAYOUT) {
            $wallet = $this->walletFor($user->id);
            $pending = (int) FinanceRequest::query()
                ->where('user_id', $user->id)
                ->where('type', FinanceRequest::MERCHANT_PAYOUT)
                ->where('status', FinanceRequest::PENDING)
                ->sum('amount');
            $this->ensure(
                $amount <= max(0, (int) $wallet->balance - $pending),
                'الرصيد المتاح لا يغطي طلب التسوية.'
            );
        }

        return FinanceRequest::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'type' => $type,
            'amount' => $amount,
            'status' => FinanceRequest::PENDING,
            'reference' => $this->reference(),
            'note' => $note,
        ]);
    }

    /**
     * The courier's actual cash exposure is derived from completed deliveries
     * minus cash handovers already recorded by administration.  It is not a
     * browser-supplied number and it does not depend on a demo wallet value.
     */
    public function cashOnHand(int $courierId): int
    {
        $courier = User::withoutGlobalScopes()->find($courierId);
        $delivered = $courier && $courier->isCourierRole()
            ? (int) app(CourierOrderAccess::class)
                ->assigned($courier)
                ->where('status', 'delivered')
                ->sum('price')
            : 0;

        $handedOver = (int) Transaction::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $courierId)
            ->where('type', FinanceRequest::CASH_HANDOVER)
            ->where('direction', -1)
            ->sum('amount');

        return max(0, $delivered - $handedOver);
    }

    /** Amount of physically settled cash that can support another recharge. */
    public function rechargeCapacity(int $courierId): int
    {
        $handedOver = (int) Transaction::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $courierId)
            ->where('type', FinanceRequest::CASH_HANDOVER)
            ->where('direction', -1)
            ->sum('amount');

        $recharged = (int) Transaction::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $courierId)
            ->where('type', FinanceRequest::BUDGET_RECHARGE)
            ->where('direction', 1)
            ->sum('amount');

        return max(0, $handedOver - $recharged);
    }

    public function approve(int $requestId, User $admin, int $approvedAmount, ?int $branchId = null, ?string $note = null): FinanceRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $approvedAmount, $branchId, $note): FinanceRequest {
            $request = FinanceRequest::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($requestId);

            $this->ensure($request->isPending(), 'تمت معالجة هذا الطلب مسبقاً.');
            $this->ensure($approvedAmount >= 1000 && $approvedAmount <= $request->amount, 'مبلغ الاعتماد غير صالح.');

            $user = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->user_id);
            // The lock serialises competing approval attempts for the same
            // courier/merchant, even for a cash handover that does not update
            // the wallet itself.
            $wallet = $this->walletFor($user->id, true);
            $effectiveBranchId = $branchId ?: $request->branch_id;

            $transaction = match ($request->type) {
                FinanceRequest::CASH_HANDOVER => $this->approveCashHandover($request, $user, $wallet, $approvedAmount, $effectiveBranchId),
                FinanceRequest::BUDGET_RECHARGE => $this->approveBudgetRecharge($request, $user, $wallet, $approvedAmount),
                FinanceRequest::MERCHANT_PAYOUT => $this->approveMerchantPayout($request, $user, $wallet, $approvedAmount),
                default => throw ValidationException::withMessages(['request' => ['نوع العملية المالية غير صالح.']]),
            };

            $request->forceFill([
                'approved_amount' => $approvedAmount,
                'branch_id' => $effectiveBranchId,
                'status' => FinanceRequest::APPROVED,
                'decision_note' => $note,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ])->save();

            ActivityLog::create([
                'tenant_id' => $request->tenant_id,
                'user_id' => $admin->id,
                'action' => 'finance.request_approved',
                'subject_type' => 'finance_request',
                'subject_id' => $request->id,
                'data' => [
                    'type' => $request->type,
                    'amount' => $approvedAmount,
                    'transaction_id' => $transaction->id,
                    'branch_id' => $effectiveBranchId,
                ],
                'ip' => request()->ip(),
            ]);

            $this->notify($request, $user, true, $approvedAmount, $note);

            return $request->fresh(['user', 'branch', 'processor']);
        });
    }

    public function reject(int $requestId, User $admin, ?string $note = null): FinanceRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $note): FinanceRequest {
            $request = FinanceRequest::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->findOrFail($requestId);
            $this->ensure($request->isPending(), 'تمت معالجة هذا الطلب مسبقاً.');

            $request->forceFill([
                'status' => FinanceRequest::REJECTED,
                'decision_note' => $note,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ])->save();

            ActivityLog::create([
                'tenant_id' => $request->tenant_id,
                'user_id' => $admin->id,
                'action' => 'finance.request_rejected',
                'subject_type' => 'finance_request',
                'subject_id' => $request->id,
                'data' => ['type' => $request->type, 'amount' => $request->amount],
                'ip' => request()->ip(),
            ]);

            $user = User::withoutGlobalScopes()->find($request->user_id);
            if ($user) {
                $this->notify($request, $user, false, $request->amount, $note);
            }

            return $request->fresh(['user', 'branch', 'processor']);
        });
    }

    private function approveCashHandover(FinanceRequest $request, User $user, Wallet $wallet, int $amount, ?int $branchId): Transaction
    {
        $this->ensure($user->isCourierRole(), 'تسليم النقدية متاح للمندوب فقط.');
        $this->ensure($branchId !== null, 'اختر الفرع الذي استلم النقدية.');
        $branch = $this->activeBranch($branchId, true);
        $this->ensure($amount <= $this->cashOnHand($user->id), 'مبلغ التسليم أكبر من النقدية التي يحملها المندوب.');

        // Do not mutate the branch balance directly: a physical handover
        // must create its cashbox voucher in the same transaction as the
        // immutable wallet ledger entry. CashboxService also synchronises the
        // legacy branch cash field used by existing dashboard summaries.
        app(CashboxService::class)->receiveCourierHandover(
            $branch,
            $user,
            $amount,
            $request->reference,
            $request->note,
        );

        return $this->ledger($request, $user, FinanceRequest::CASH_HANDOVER, $amount, -1, 'تم استلام النقدية في '.$branch->name_ar);
    }

    private function approveBudgetRecharge(FinanceRequest $request, User $user, Wallet $wallet, int $amount): Transaction
    {
        $this->ensure($user->isCourierRole(), 'شحن الميزانية متاح للمندوب فقط.');
        $this->ensure(
            $amount <= $this->rechargeCapacity($user->id),
            'لا يمكن شحن ميزانية أكبر من النقدية المسلّمة وغير المستخدمة.'
        );

        $wallet->increment('budget', $amount);

        return $this->ledger($request, $user, FinanceRequest::BUDGET_RECHARGE, $amount, 1, 'شحن ميزانية بعد اعتماد تسليم النقدية');
    }

    private function approveMerchantPayout(FinanceRequest $request, User $user, Wallet $wallet, int $amount): Transaction
    {
        $this->ensure($user->role === 'merchant', 'تسوية التاجر متاحة لحسابات التجار فقط.');
        $this->ensure($wallet->balance >= $amount, 'رصيد التاجر لا يغطي مبلغ التسوية.');

        $wallet->decrement('balance', $amount);
        $this->syncLegacyMerchantTenantBalance($user, (int) $wallet->fresh()->balance);

        return $this->ledger($request, $user, FinanceRequest::MERCHANT_PAYOUT, $amount, -1, 'تسوية مستحقات التاجر من الإدارة');
    }

    private function ledger(FinanceRequest $request, User $user, string $type, int $amount, int $direction, string $fallbackNote): Transaction
    {
        return Transaction::create([
            'finance_request_id' => $request->id,
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => $type,
            'amount' => $amount,
            'direction' => $direction,
            'ref' => $request->reference,
            'date' => today(),
            'note' => $request->note ?: $fallbackNote,
        ]);
    }

    private function walletFor(int $userId, bool $lock = false): Wallet
    {
        $query = Wallet::query()->where('user_id', $userId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $wallet = $query->first();

        if ($wallet) {
            return $wallet;
        }

        Wallet::firstOrCreate(['user_id' => $userId], ['balance' => 0, 'budget' => 0]);

        return Wallet::query()
            ->where('user_id', $userId)
            ->when($lock, fn ($walletQuery) => $walletQuery->lockForUpdate())
            ->firstOrFail();
    }

    /**
     * The wallet is authoritative. Keep the historic tenant amount aligned
     * only while older reports/imports still include that column.
     */
    private function syncLegacyMerchantTenantBalance(User $user, int $balance): void
    {
        if ($user->role !== 'merchant' || ! $user->tenant_id) {
            return;
        }

        Tenant::query()->whereKey($user->tenant_id)->update([
            'wallet_balance' => max(0, $balance),
        ]);
    }

    private function activeBranch(int $branchId, bool $lock = false): Branch
    {
        $query = Branch::withoutGlobalScope(TenantScope::class)
            ->where('is_active', true)
            ->whereKey($branchId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function notify(FinanceRequest $request, User $user, bool $approved, int $amount, ?string $note): void
    {
        $labels = [
            FinanceRequest::CASH_HANDOVER => ['تسليم النقدية', 'Cash handover'],
            FinanceRequest::BUDGET_RECHARGE => ['شحن الميزانية', 'Budget recharge'],
            FinanceRequest::MERCHANT_PAYOUT => ['تسوية المستحقات', 'Payout settlement'],
        ];
        [$ar, $en] = $labels[$request->type] ?? ['العملية المالية', 'Financial request'];
        $stateAr = $approved ? 'تم اعتماد' : 'تم رفض';
        $stateEn = $approved ? 'Approved' : 'Rejected';

        Notification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => 'finance',
            'title_ar' => $stateAr.' '.$ar,
            'title_en' => $stateEn.' '.$en,
            'title_ku' => $stateEn.' '.$en,
            'body_ar' => $approved
                ? 'تمت معالجة الطلب '.$request->reference.' بمبلغ '.number_format($amount).' د.ع.'
                : 'لم تتم الموافقة على الطلب '.$request->reference.'.'.($note ? ' السبب: '.$note : ''),
            'body_en' => $approved
                ? 'Request '.$request->reference.' was processed for '.number_format($amount).' IQD.'
                : 'Request '.$request->reference.' was rejected.'.($note ? ' Reason: '.$note : ''),
            'body_ku' => $approved
                ? 'Request '.$request->reference.' was processed for '.number_format($amount).' IQD.'
                : 'Request '.$request->reference.' was rejected.'.($note ? ' Reason: '.$note : ''),
            'data' => ['finance_request_id' => $request->id, 'status' => $approved ? FinanceRequest::APPROVED : FinanceRequest::REJECTED],
        ]);
    }

    private function reference(): string
    {
        return 'FIN-'.now()->format('ymdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['finance' => [$message]]);
        }
    }
}
