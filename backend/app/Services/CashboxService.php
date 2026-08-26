<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CashboxVoucher;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An auditable double-entry layer for the physical cashboxes controlled by
 * the dashboard. Wallet transactions remain party ledgers; cashbox vouchers
 * describe where the physical money actually moved.
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
     * Posts a confirmed courier cash handover to the receiving branch.  The
     * finance request and the physical cashbox entry share one reference so
     * an accountant can reconcile the digital ledger with cash on site.
     */
    public function receiveCourierHandover(Branch $branch, User $courier, int $amount, string $reference, ?string $note = null): CashboxVoucher
    {
        $platform = Tenant::platform();
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

        return $this->record(
            $cashbox,
            1,
            $amount,
            'courier_handover',
            $courier,
            $note,
            $reference,
            ['courier_id' => $courier->id, 'branch_id' => $branch->id],
        );
    }

    public function record(
        Cashbox $cashbox,
        int $direction,
        int $amount,
        string $type,
        User $actor,
        ?string $note = null,
        ?string $reference = null,
        ?array $meta = null,
    ): CashboxVoucher {
        if (! in_array($direction, [-1, 1], true) || $amount < 1 || ! in_array($type, CashboxVoucher::TYPES, true)) {
            throw ValidationException::withMessages(['cashbox' => __('Invalid cashbox voucher data.')]);
        }

        return DB::transaction(function () use ($cashbox, $direction, $amount, $type, $actor, $note, $reference, $meta): CashboxVoucher {
            $cashbox = Cashbox::withoutGlobalScopes()->lockForUpdate()->findOrFail($cashbox->id);
            $this->assertActive($cashbox);

            if ($direction < 0 && (int) $cashbox->balance < $amount) {
                throw ValidationException::withMessages(['amount' => __('The selected cashbox has insufficient balance.')]);
            }

            $cashbox->update(['balance' => (int) $cashbox->balance + ($direction * $amount)]);
            $this->syncBranchBalance($cashbox);

            return CashboxVoucher::withoutGlobalScopes()->create([
                'tenant_id' => $cashbox->tenant_id,
                'cashbox_id' => $cashbox->id,
                'branch_id' => $cashbox->branch_id,
                'actor_id' => $actor->id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'reference' => $reference ?: $this->reference('CV'),
                'note' => $note,
                'meta' => $meta,
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

            if ((int) $from->balance < $amount) {
                throw ValidationException::withMessages(['amount' => __('The source cashbox has insufficient balance.')]);
            }

            $reference = $this->reference('CBT');
            $from->update(['balance' => (int) $from->balance - $amount]);
            $to->update(['balance' => (int) $to->balance + $amount]);
            $this->syncBranchBalance($from);
            $this->syncBranchBalance($to);

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
                'meta' => ['counterparty' => $to->id],
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
                'meta' => ['counterparty' => $from->id],
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

    private function syncBranchBalance(Cashbox $cashbox): void
    {
        if ($cashbox->branch_id) {
            Branch::withoutGlobalScopes()
                ->whereKey($cashbox->branch_id)
                ->update(['cash_balance' => $cashbox->balance]);
        }
    }

    private function reference(string $prefix): string
    {
        return sprintf('%s-%s-%04d', $prefix, now()->format('ymdHis'), random_int(1000, 9999));
    }
}
