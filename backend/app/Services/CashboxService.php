<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CashboxVoucher;
use App\Models\FinanceRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An auditable custody ledger for courier delivery collections controlled by
 * the dashboard. Wallet transactions (Qi credit and courier cash budget)
 * are party ledgers and never belong to a cashbox.
 *
 * The only external inflow is an approved courier handover whose amount was
 * verified against delivered-order collections. Transfers are allowed solely
 * to move that same collection between custody locations; they never create
 * or consume collection revenue.
 */
class CashboxService
{
    public function ensureOperatingCashboxes(): void
    {
        $platform = Tenant::platform();

        Branch::withoutGlobalScopes()
            ->where('is_platform_managed', true)
            ->get()
            ->each(function (Branch $branch) use ($platform): void {
                Cashbox::withoutGlobalScopes()->firstOrCreate(
                    ['branch_id' => $branch->id],
                    [
                        'tenant_id' => $platform->id,
                        'kind' => 'branch',
                        'name_ar' => 'صندوق '.$branch->name_ar,
                        'name_en' => 'Cashbox '.$branch->name_en,
                        'name_ku' => 'سندووقی '.$branch->name_ku,
                        'balance' => max(0, (int) $branch->cash_balance),
                        'is_active' => (bool) $branch->is_active,
                    ],
                );
            });

        Cashbox::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $platform->id, 'kind' => 'vault', 'branch_id' => null],
            ['name_ar' => 'الخزنة المركزية', 'name_en' => 'Central vault', 'name_ku' => 'خەزنەی ناوەندی', 'balance' => 0, 'is_active' => true],
        );

        Cashbox::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $platform->id, 'kind' => 'bank', 'branch_id' => null],
            ['name_ar' => 'صندوق البنك', 'name_en' => 'Bank account', 'name_ku' => 'حسابی بانک', 'balance' => 0, 'is_active' => true],
        );
    }

    /**
     * Returns only the balance whose provenance is a courier delivery
     * collection. Legacy/manual voucher rows and a historical cached balance
     * are intentionally excluded without deleting either of them.
     */
    public function collectionBalance(Cashbox|int $cashbox): int
    {
        $cashboxId = $cashbox instanceof Cashbox ? $cashbox->id : $cashbox;

        return max(0, (int) CashboxVoucher::withoutGlobalScopes()
            ->where('cashbox_id', $cashboxId)
            ->whereIn('type', CashboxVoucher::COLLECTION_CUSTODY_TYPES)
            ->get(['direction', 'amount'])
            ->sum(fn (CashboxVoucher $voucher): int => (int) $voucher->direction * (int) $voucher->amount));
    }

    /**
     * @param iterable<int, Cashbox> $cashboxes
     * @return array<int, int>
     */
    public function collectionBalances(iterable $cashboxes): array
    {
        $ids = collect($cashboxes)
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return CashboxVoucher::withoutGlobalScopes()
            ->whereIn('cashbox_id', $ids)
            ->whereIn('type', CashboxVoucher::COLLECTION_CUSTODY_TYPES)
            ->get(['cashbox_id', 'direction', 'amount'])
            ->groupBy('cashbox_id')
            ->map(fn ($vouchers): int => max(0, (int) $vouchers->sum(
                fn (CashboxVoucher $voucher): int => (int) $voucher->direction * (int) $voucher->amount
            )))
            ->all();
    }

    /**
     * Posts the only allowed cashbox inflow: an approved courier delivery
     * collection handover to the receiving platform branch.  The finance
     * request and physical voucher share one reference so the entry is
     * traceable back to the delivered-order collection validation.
     */
    public function receiveCourierHandover(
        Branch $branch,
        User $courier,
        FinanceRequest $request,
        int $amount,
        int $availableCollections,
        ?string $note = null,
    ): CashboxVoucher
    {
        $platform = Tenant::platform();
        $this->assertEligibleHandover($branch, $courier, $request, $amount, $availableCollections, $platform);

        $cashbox = Cashbox::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->lockForUpdate()
            ->first();

        if (! $cashbox) {
            // The finance service holds the receiving branch lock, so two
            // concurrent handovers cannot create two branch cashboxes.
            $cashbox = Cashbox::withoutGlobalScopes()->create([
                'tenant_id' => $platform->id,
                'branch_id' => $branch->id,
                'kind' => 'branch',
                'name_ar' => 'صندوق '.$branch->name_ar,
                'name_en' => 'Cashbox '.$branch->name_en,
                'name_ku' => 'سندووقی '.$branch->name_ku,
                'balance' => max(0, (int) $branch->cash_balance),
                'is_active' => (bool) $branch->is_active,
            ]);
        }

        return $this->recordCourierCollection(
            $cashbox,
            $amount,
            $courier,
            $note,
            $request,
            $availableCollections,
        );
    }

    /**
     * Generic receipts, payments, merchant settlements, opening balances,
     * and wallet movements are intentionally not supported here.  They are
     * not delivery collection revenue and must stay outside this ledger.
     */
    private function recordCourierCollection(
        Cashbox $cashbox,
        int $amount,
        User $actor,
        ?string $note = null,
        FinanceRequest $request,
        int $availableCollections,
    ): CashboxVoucher {
        if ($amount < 1) {
            throw ValidationException::withMessages(['cashbox' => __('Invalid cashbox voucher data.')]);
        }

        return DB::transaction(function () use ($cashbox, $amount, $actor, $note, $request, $availableCollections): CashboxVoucher {
            $cashbox = Cashbox::withoutGlobalScopes()->lockForUpdate()->findOrFail($cashbox->id);
            $this->assertActive($cashbox);
            $this->assertCollectionCashbox($cashbox);

            if (CashboxVoucher::withoutGlobalScopes()
                ->where('type', 'courier_handover')
                ->where('reference', $request->reference)
                ->exists()) {
                throw ValidationException::withMessages([
                    'cashbox' => 'تم ترحيل تسليم النقدية هذا إلى الصندوق مسبقاً.',
                ]);
            }

            // The cached balance may contain a legacy manual entry. Rebase it
            // on the auditable collection ledger rather than adding to that
            // old number, so a new handover never revives non-delivery cash.
            $collectionBalance = $this->collectionBalance($cashbox);
            $newCollectionBalance = $collectionBalance + $amount;
            $cashbox->update(['balance' => $newCollectionBalance]);
            $this->syncBranchBalance($cashbox, $newCollectionBalance);

            return CashboxVoucher::withoutGlobalScopes()->create([
                'tenant_id' => $cashbox->tenant_id,
                'cashbox_id' => $cashbox->id,
                'branch_id' => $cashbox->branch_id,
                'actor_id' => $actor->id,
                'type' => 'courier_handover',
                'direction' => 1,
                'amount' => $amount,
                'reference' => $request->reference,
                'note' => $note,
                'meta' => [
                    'collection_source' => 'delivered_order_collections',
                    'finance_request_id' => $request->id,
                    'finance_request_reference' => $request->reference,
                    'courier_id' => $actor->id,
                    'branch_id' => $cashbox->branch_id,
                    'available_collections_before_handover' => $availableCollections,
                ],
                'occurred_at' => now(),
            ]);
        });
    }

    /** @return array{out: CashboxVoucher, in: CashboxVoucher} */
    public function transfer(Cashbox $from, Cashbox $to, int $amount, User $actor, ?string $note = null): array
    {
        if ($from->id === $to->id || $amount < 1) {
            throw ValidationException::withMessages(['cashbox' => __('Choose two different cashboxes and a valid amount.')]);
        }

        return DB::transaction(function () use ($from, $to, $amount, $actor, $note): array {
            $boxes = Cashbox::withoutGlobalScopes()
                ->whereIn('id', [$from->id, $to->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $from = $boxes->get($from->id) ?? throw ValidationException::withMessages(['from_cashbox_id' => __('Cashbox not found.')]);
            $to = $boxes->get($to->id) ?? throw ValidationException::withMessages(['to_cashbox_id' => __('Cashbox not found.')]);
            $this->assertActive($from);
            $this->assertActive($to);
            $this->assertCollectionCashbox($from);
            $this->assertCollectionCashbox($to);

            // Do not use the cached balance here: legacy/manual entries may
            // still exist for audit history but must never be moved as
            // delivery-collection cash.
            $fromCollectionBalance = $this->collectionBalance($from);
            $toCollectionBalance = $this->collectionBalance($to);

            if ($fromCollectionBalance < $amount) {
                throw ValidationException::withMessages(['amount' => __('The source cashbox has insufficient balance.')]);
            }

            $reference = $this->reference('CBT');
            // As above, use collection-ledger values rather than a cached
            // historic book value. This also prevents an old manual payment
            // from making an otherwise valid collection transfer underflow.
            $fromBalanceAfterTransfer = $fromCollectionBalance - $amount;
            $toBalanceAfterTransfer = $toCollectionBalance + $amount;
            $from->update(['balance' => $fromBalanceAfterTransfer]);
            $to->update(['balance' => $toBalanceAfterTransfer]);
            $this->syncBranchBalance($from, $fromBalanceAfterTransfer);
            $this->syncBranchBalance($to, $toBalanceAfterTransfer);

            $out = CashboxVoucher::withoutGlobalScopes()->create([
                'tenant_id' => $from->tenant_id,
                'cashbox_id' => $from->id,
                'counterparty_cashbox_id' => $to->id,
                'branch_id' => $from->branch_id,
                'actor_id' => $actor->id,
                'type' => 'transfer_out',
                'direction' => -1,
                'amount' => $amount,
                'reference' => $reference,
                'note' => $note,
                'meta' => ['counterparty' => $to->id, 'collection_custody_transfer' => true],
                'occurred_at' => now(),
            ]);

            $in = CashboxVoucher::withoutGlobalScopes()->create([
                'tenant_id' => $to->tenant_id,
                'cashbox_id' => $to->id,
                'counterparty_cashbox_id' => $from->id,
                'branch_id' => $to->branch_id,
                'actor_id' => $actor->id,
                'type' => 'transfer_in',
                'direction' => 1,
                'amount' => $amount,
                'reference' => $reference,
                'note' => $note,
                'meta' => ['counterparty' => $from->id, 'collection_custody_transfer' => true],
                'occurred_at' => now(),
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }

    private function assertActive(Cashbox $cashbox): void
    {
        if (! $cashbox->is_active) {
            throw ValidationException::withMessages(['cashbox' => __('The selected cashbox is inactive.')]);
        }
    }

    private function assertEligibleHandover(
        Branch $branch,
        User $courier,
        FinanceRequest $request,
        int $amount,
        int $availableCollections,
        Tenant $platform,
    ): void {
        $valid = $amount > 0
            && $amount <= $availableCollections
            && $amount <= (int) $request->amount
            && $request->type === FinanceRequest::CASH_HANDOVER
            && $request->status === FinanceRequest::APPROVED
            && (int) $request->approved_amount === $amount
            && (int) $request->user_id === (int) $courier->id
            && (int) $request->branch_id === (int) $branch->id
            && (bool) $branch->is_platform_managed
            && (int) $branch->tenant_id === (int) $platform->id;

        if (! $valid) {
            throw ValidationException::withMessages([
                'cashbox' => 'لا يمكن إضافة مبلغ للصندوق إلا من تسليم نقدي معتمد ومربوط بتحصيلات طلبات مسلّمة.',
            ]);
        }
    }

    private function assertCollectionCashbox(Cashbox $cashbox): void
    {
        $platformId = Tenant::platform()->id;

        if ((int) $cashbox->tenant_id !== (int) $platformId || ! in_array($cashbox->kind, Cashbox::KINDS, true)) {
            throw ValidationException::withMessages([
                'cashbox' => 'هذا الصندوق ليس ضمن صناديق تحصيل إيرادات التوصيل.',
            ]);
        }
    }

    private function syncBranchBalance(Cashbox $cashbox, int $collectionBalance): void
    {
        if ($cashbox->branch_id) {
            Branch::withoutGlobalScopes()
                ->whereKey($cashbox->branch_id)
                ->update(['cash_balance' => $collectionBalance]);
        }
    }

    private function reference(string $prefix): string
    {
        return sprintf('%s-%s-%04d', $prefix, now()->format('ymdHis'), random_int(1000, 9999));
    }
}
