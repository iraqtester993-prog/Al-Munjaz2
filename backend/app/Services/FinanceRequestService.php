<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\FinanceRequest;
use App\Models\Notification;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns every balance-changing finance operation.
 *
 * Finance requests remain pending until an administrator confirms the
 * settlement. A courier's declared cash budget is the single exception: it
 * is immediately usable for taking merchant orders, while still being posted
 * to the immutable ledger and activity log for administrative review.
 */
class FinanceRequestService
{
    public function submit(
        User $user,
        string $type,
        int $amount,
        ?int $branchId = null,
        ?string $note = null,
        ?string $externalReference = null,
    ): FinanceRequest
    {
        $this->ensure(in_array($type, FinanceRequest::TYPES, true), 'نوع العملية المالية غير صالح.');
        $this->ensure($amount >= 1000, 'الحد الأدنى للعملية هو 1,000 د.ع.');

        if (in_array($type, [FinanceRequest::CASH_HANDOVER, FinanceRequest::BUDGET_RECHARGE, FinanceRequest::QI_TOPUP], true)) {
            $this->ensure($user->isCourierRole(), 'هذه العملية متاحة للمندوب فقط.');
        }

        if ($type === FinanceRequest::MERCHANT_PAYOUT) {
            $this->ensure($user->role === 'merchant', 'طلب التسوية متاح للتاجر فقط.');
        }

        if ($type === FinanceRequest::QI_TOPUP && filled($externalReference)) {
            $this->ensure(
                ! FinanceRequest::withoutGlobalScope(TenantScope::class)
                    ->where('type', FinanceRequest::QI_TOPUP)
                    ->where('external_reference', $externalReference)
                    ->exists(),
                'رقم عملية Qi مستخدم مسبقاً في طلب شحن آخر.'
            );
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
            'external_reference' => $externalReference,
            'note' => $note,
        ]);
    }

    /**
     * A courier declares cash physically available to collect a merchant
     * order. This must be available immediately, but is never an invisible
     * browser-only value: the wallet update, ledger entry, and activity audit
     * are created atomically.
     */
    public function declareCourierBudget(User $user, int $amount, ?string $note = null): Transaction
    {
        $this->ensure($user->isCourierRole(), 'إضافة الميزانية متاحة للمندوب فقط.');
        $this->ensure($amount >= 1000, 'الحد الأدنى للعملية هو 1,000 د.ع.');

        return DB::transaction(function () use ($user, $amount, $note): Transaction {
            $courier = User::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($user->id);
            $wallet = $this->walletFor($courier->id, true);
            $budgetBefore = (int) $wallet->budget;
            $reference = $this->reference('BUD');
            $ledgerNote = trim((string) $note);

            $wallet->increment('budget', $amount);

            $transaction = Transaction::create([
                'tenant_id' => $courier->tenant_id,
                'user_id' => $courier->id,
                'type' => FinanceRequest::BUDGET_RECHARGE,
                'amount' => $amount,
                'direction' => 1,
                'ref' => $reference,
                'date' => today(),
                'note' => mb_substr(
                    $ledgerNote !== '' ? $ledgerNote : 'إضافة ميزانية نقدية من المندوب',
                    0,
                    255,
                ),
            ]);

            ActivityLog::create([
                'tenant_id' => $courier->tenant_id,
                'user_id' => $courier->id,
                'action' => 'wallet.courier_budget_added',
                'subject_type' => 'wallet',
                'subject_id' => $wallet->id,
                'data' => [
                    'amount' => $amount,
                    'budget_before' => $budgetBefore,
                    'budget_after' => $budgetBefore + $amount,
                    'transaction_id' => $transaction->id,
                    'reference' => $reference,
                    'source' => 'courier_declaration',
                ],
                'ip' => request()->ip(),
            ]);

            return $transaction;
        });
    }

    /**
     * Physical cash still held by a courier is based on net collections
     * (delivery value less the company fee) minus approved branch handovers.
     * It is never a browser-supplied number and is separate from the Qi
     * wallet credit used for future fee deductions.
     */
    public function cashOnHand(int $courierId): int
    {
        $courier = User::withoutGlobalScopes()->find($courierId);
        $delivered = $courier && $courier->isCourierRole()
            ? $this->collectionsTotal($courier)
            : 0;

        $handedOver = (int) Transaction::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $courierId)
            ->where('type', FinanceRequest::CASH_HANDOVER)
            ->where('direction', -1)
            ->sum('amount');

        return max(0, $delivered - $handedOver);
    }

    /**
     * Courier collections are what remains after the platform fee on every
     * delivered order. Keeping this computation on persisted orders also
     * makes old data present correctly after the fee policy changes.
     */
    public function collectionsTotal(User|int $courier): int
    {
        $courier = $courier instanceof User
            ? $courier
            : User::withoutGlobalScopes()->find($courier);

        if (! $courier || ! $courier->isCourierRole()) {
            return 0;
        }

        return app(CourierOrderAccess::class)
            ->assigned($courier)
            ->where('status', 'delivered')
            ->get(['price', 'fee'])
            ->sum(fn (Order $order): int => self::netCollectionForOrder($order));
    }

    /** The net collection is used by the wallet, dashboard, and settlement ledger. */
    public static function netCollectionForOrder(Order $order): int
    {
        $orderValue = max(0, (int) $order->price);
        $companyFee = min($orderValue, max(0, (int) $order->fee));

        return $orderValue - $companyFee;
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

            // Mark the request approved before it can enter the restricted
            // cashbox path.  This all runs inside the same transaction, so a
            // later failure rolls the state back; however CashboxService can
            // now reject any call that is not tied to an approved handover.
            $request->forceFill([
                'approved_amount' => $approvedAmount,
                'branch_id' => $effectiveBranchId,
                'status' => FinanceRequest::APPROVED,
                'decision_note' => $note,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ])->save();

            $transaction = match ($request->type) {
                FinanceRequest::CASH_HANDOVER => $this->approveCashHandover($request, $user, $wallet, $approvedAmount, $effectiveBranchId),
                FinanceRequest::BUDGET_RECHARGE => $this->approveBudgetRecharge($request, $user, $wallet, $approvedAmount),
                FinanceRequest::QI_TOPUP => $this->approveQiTopUp($request, $user, $wallet, $approvedAmount),
                FinanceRequest::MERCHANT_PAYOUT => $this->approveMerchantPayout($request, $user, $wallet, $approvedAmount),
                default => throw ValidationException::withMessages(['request' => ['نوع العملية المالية غير صالح.']]),
            };

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
        $availableCollections = $this->cashOnHand($user->id);
        $this->ensure($amount <= $availableCollections, 'مبلغ التسليم أكبر من النقدية التي يحملها المندوب.');

        // Do not mutate the branch balance directly: a physical handover
        // must create its cashbox voucher in the same transaction as the
        // immutable wallet ledger entry. CashboxService also synchronises the
        // legacy branch cash field used by existing dashboard summaries.
        app(CashboxService::class)->receiveCourierHandover(
            $branch,
            $user,
            $request,
            $amount,
            $availableCollections,
            $request->note,
        );

        return $this->ledger($request, $user, FinanceRequest::CASH_HANDOVER, $amount, -1, 'تم استلام النقدية في '.$branch->name_ar);
    }

    private function approveBudgetRecharge(FinanceRequest $request, User $user, Wallet $wallet, int $amount): Transaction
    {
        $this->ensure($user->isCourierRole(), 'إضافة الميزانية متاحة للمندوب فقط.');

        $wallet->increment('budget', $amount);

        return $this->ledger($request, $user, FinanceRequest::BUDGET_RECHARGE, $amount, 1, 'إضافة نقدية إلى ميزانية المندوب بعد اعتماد الإدارة');
    }

    private function approveQiTopUp(FinanceRequest $request, User $user, Wallet $wallet, int $amount): Transaction
    {
        $this->ensure($user->isCourierRole(), 'شحن رصيد Qi متاح للمندوب فقط.');

        $wallet->increment('balance', $amount);

        return $this->ledger(
            $request,
            $user,
            FinanceRequest::QI_TOPUP,
            $amount,
            1,
            $request->external_reference
                ? 'شحن رصيد Qi بالمرجع '.$request->external_reference
                : 'إضافة رصيد Qi من الإدارة',
        );
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
            ->where('is_platform_managed', true)
            ->where('tenant_id', Tenant::platform()->id)
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
            FinanceRequest::BUDGET_RECHARGE => ['إضافة الميزانية النقدية', 'Cash budget added'],
            FinanceRequest::QI_TOPUP => ['شحن رصيد Qi', 'Qi balance top up'],
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

    private function reference(string $prefix = 'FIN'): string
    {
        return $prefix.'-'.now()->format('ymdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['finance' => [$message]]);
        }
    }
}
