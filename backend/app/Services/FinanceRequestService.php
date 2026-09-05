<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\FinanceRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
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
    ): FinanceRequest {
        $this->ensure(in_array($type, FinanceRequest::TYPES, true), 'نوع العملية المالية غير صالح.');
        $this->ensure($amount >= 1000, 'الحد الأدنى للعملية هو 1,000 د.ع.');

        if (in_array($type, [FinanceRequest::CASH_HANDOVER, FinanceRequest::BUDGET_RECHARGE, FinanceRequest::QI_TOPUP], true)) {
            // A direct order has one accountable courier. Retired pickup,
            // delivery, and transporter accounts remain visible in audit
            // history but cannot start new wallet operations.
            $this->ensure($this->isDirectCourier($user), 'هذه العملية متاحة للمندوب فقط.');
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

        if ($type === FinanceRequest::CASH_HANDOVER) {
            // A cash handover is physical custody, not a courier-selected
            // destination. Resolve the one active operational branch from
            // the server-owned courier assignment and persist only that id.
            $operationalBranch = $this->requireCourierOperationalBranch($user);
            $this->ensure(
                $branchId === null || $branchId === (int) $operationalBranch->id,
                'لا يمكن تسليم النقدية إلا إلى فرع المندوب التشغيلي.'
            );
            $branchId = (int) $operationalBranch->id;
        } elseif ($branchId) {
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
        $this->ensure($this->isDirectCourier($user), 'إضافة الميزانية متاحة للمندوب فقط.');
        $this->ensure($amount >= 1000, 'الحد الأدنى للعملية هو 1,000 د.ع.');

        return DB::transaction(function () use ($user, $amount, $note): Transaction {
            $courier = User::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($user->id);
            $wallet = $this->walletFor($courier->id, true);
            $budgetBefore = (int) $wallet->budget;
            $budgetBalanceBefore = (int) $wallet->budget_balance;
            $reference = $this->reference('BUD');
            $ledgerNote = trim((string) $note);

            // Adding real cash increases both the declared budget ceiling
            // and the portion currently available for new orders. Order
            // assignment itself changes only budget_balance.
            $wallet->increment('budget', $amount);
            $wallet->increment('budget_balance', $amount);

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
                    'budget_balance_before' => $budgetBalanceBefore,
                    'budget_balance_after' => $budgetBalanceBefore + $amount,
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
     * Remove only cash that is not currently reserved for an active order.
     * Both budget figures move together, so the declared cash ceiling never
     * becomes lower than funds already held for an order.
     */
    public function reduceCourierBudget(User $user, int $amount, ?string $note = null): Transaction
    {
        $this->ensure($this->isDirectCourier($user), 'إنقاص الميزانية متاح للمندوب فقط.');
        $this->ensure($amount >= 1000, 'الحد الأدنى للعملية هو 1,000 د.ع.');

        return DB::transaction(function () use ($user, $amount, $note): Transaction {
            $courier = User::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($user->id);
            $wallet = $this->walletFor($courier->id, true);
            $budgetBefore = (int) $wallet->budget;
            $budgetBalanceBefore = (int) $wallet->budget_balance;

            $this->ensure(
                $amount <= $budgetBefore && $amount <= $budgetBalanceBefore,
                'لا يمكن إنقاص مبلغ محجوز لطلب نشط أو أكبر من الميزانية المتاحة.'
            );

            $wallet->decrement('budget', $amount);
            $wallet->decrement('budget_balance', $amount);
            $reference = $this->reference('BUD');
            $ledgerNote = trim((string) $note);

            $transaction = Transaction::create([
                'tenant_id' => $courier->tenant_id,
                'user_id' => $courier->id,
                'type' => 'budget_deduct',
                'amount' => $amount,
                'direction' => -1,
                'ref' => $reference,
                'date' => today(),
                'note' => mb_substr(
                    $ledgerNote !== '' ? $ledgerNote : 'إنقاص ميزانية نقدية من المندوب',
                    0,
                    255,
                ),
            ]);

            ActivityLog::create([
                'tenant_id' => $courier->tenant_id,
                'user_id' => $courier->id,
                'action' => 'wallet.courier_budget_reduced',
                'subject_type' => 'wallet',
                'subject_id' => $wallet->id,
                'data' => [
                    'amount' => $amount,
                    'budget_before' => $budgetBefore,
                    'budget_after' => $budgetBefore - $amount,
                    'budget_balance_before' => $budgetBalanceBefore,
                    'budget_balance_after' => $budgetBalanceBefore - $amount,
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
     * The wallet UI may expose this one branch as the handover destination.
     * A null return deliberately means the courier has no valid operational
     * assignment, rather than falling back to a browser-selected branch.
     */
    public function operationalBranchForCourier(User $courier): ?Branch
    {
        if (! $this->isDirectCourier($courier) || (int) $courier->branch_id <= 0) {
            return null;
        }

        return $this->operationalBranchQuery($courier)->first();
    }

    /**
     * Physical cash still held by a courier is based on net collections
     * (delivery charge less the fixed administration deduction) minus
     * approved branch handovers.
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
     * Courier collections are the delivery charges remaining after the fixed
     * deduction snapshotted on each accepted order.
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
            ->get(['fee', 'admin_deduction_applied'])
            ->sum(fn (Order $order): int => self::netCollectionForOrder($order));
    }

    /** The net collection is used by the wallet, dashboard, and settlement ledger. */
    public static function netCollectionForOrder(Order $order): int
    {
        $deliveryCharge = max(0, (int) $order->fee);
        $companyFee = max(0, (int) ($order->admin_deduction_applied ?? 0));

        return max(0, $deliveryCharge - $companyFee);
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

            if ($request->type === FinanceRequest::CASH_HANDOVER) {
                $operationalBranch = $this->requireCourierOperationalBranch($user, true);
                $expectedBranchId = (int) $operationalBranch->id;

                // A historical request without a branch may be repaired by
                // approval, but a request already tied to another branch is
                // malformed and must never move cash into that cashbox.
                $this->ensure(
                    $request->branch_id === null || (int) $request->branch_id === $expectedBranchId,
                    'طلب تسليم النقدية لا يطابق فرع المندوب التشغيلي.'
                );
                $this->ensure(
                    $effectiveBranchId === null || (int) $effectiveBranchId === $expectedBranchId,
                    'لا يمكن اعتماد التسليم النقدي في فرع مختلف عن فرع المندوب.'
                );

                $effectiveBranchId = $expectedBranchId;
            }

            $this->assertBranchOperatorCanProcess($admin, $request, $user, $effectiveBranchId);

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

            $user = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->user_id);
            $this->assertBranchOperatorCanProcess($admin, $request, $user, $request->branch_id ? (int) $request->branch_id : null);

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

            $this->notify($request, $user, false, $request->amount, $note);

            return $request->fresh(['user', 'branch', 'processor']);
        });
    }

    private function approveCashHandover(FinanceRequest $request, User $user, Wallet $wallet, int $amount, ?int $branchId): Transaction
    {
        $this->ensure($this->isDirectCourier($user), 'تسليم النقدية متاح للمندوب فقط.');
        $this->ensure($branchId !== null, 'اختر الفرع الذي استلم النقدية.');
        $branch = $this->requireCourierOperationalBranch($user, true);
        $this->ensure((int) $branch->id === $branchId, 'لا يمكن اعتماد التسليم النقدي في فرع مختلف عن فرع المندوب.');
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
        $wallet->increment('budget_balance', $amount);

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

        Wallet::firstOrCreate(['user_id' => $userId], ['balance' => 0, 'budget' => 0, 'budget_balance' => 0]);

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

    /**
     * @return Builder<Branch>
     */
    private function operationalBranchQuery(User $courier): Builder
    {
        return Branch::withoutGlobalScope(TenantScope::class)
            ->whereKey((int) $courier->branch_id)
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->whereNotNull('province_id')
            ->whereColumn('active_platform_province_id', 'province_id')
            ->whereHas('province', fn (Builder $province) => $province->platform()->active());
    }

    private function requireCourierOperationalBranch(User $courier, bool $lock = false): Branch
    {
        $query = $this->operationalBranchQuery($courier);

        if ($lock) {
            $query->lockForUpdate();
        }

        $branch = $query->first();
        $this->ensure($branch !== null, 'المندوب غير مرتبط بفرع تشغيلي نشط.');

        return $branch;
    }

    /**
     * A branch dashboard can process only its own operational accounts.
     * This is repeated in the service so a direct service call, queue job,
     * or malformed legacy request cannot bypass the controller query scope.
     */
    private function assertBranchOperatorCanProcess(User $admin, FinanceRequest $request, User $account, ?int $effectiveBranchId): void
    {
        $scope = app(BranchDashboardContext::class)->scopeFor($admin);

        if (! $scope->requiresBranchScope()) {
            return;
        }

        $this->ensure($scope->hasBranchScope(), 'حساب مدير الفرع غير مرتبط بفرع تشغيلي نشط.');
        $branchId = (int) $scope->branchId();

        $this->ensure(
            (int) $account->branch_id === $branchId,
            'لا يمكن لمدير الفرع معالجة طلب تابع لمندوب أو تاجر من فرع آخر.'
        );
        $this->ensure(
            $request->branch_id === null || (int) $request->branch_id === $branchId,
            'لا يمكن لمدير الفرع معالجة طلب مرتبط بفرع آخر.'
        );
        $this->ensure(
            $effectiveBranchId === null || (int) $effectiveBranchId === $branchId,
            'لا يمكن لمدير الفرع تحويل العملية إلى فرع آخر.'
        );
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

    private function isDirectCourier(User $user): bool
    {
        return $user->role === 'courier';
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['finance' => [$message]]);
        }
    }
}
