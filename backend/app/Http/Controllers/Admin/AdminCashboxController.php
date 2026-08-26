<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cashbox;
use App\Models\CashboxVoucher;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\CashboxService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminCashboxController extends Controller
{
    public function index(CashboxService $cashboxes)
    {
        $cashboxes->ensureOperatingCashboxes();

        $boxes = Cashbox::withoutGlobalScope(TenantScope::class)
            ->with('branch:id,name_ar,name_en,name_ku,city')
            // ANSI CASE keeps the operating order portable across the
            // production MySQL database and SQLite used by automated tests.
            ->orderByRaw("CASE kind WHEN 'branch' THEN 1 WHEN 'vault' THEN 2 WHEN 'bank' THEN 3 ELSE 4 END")
            ->orderBy('name_ar')
            ->get()
            ->map(fn (Cashbox $box) => $this->boxPayload($box));

        $vouchers = CashboxVoucher::withoutGlobalScope(TenantScope::class)
            ->with([
                'cashbox:id,name_ar,name_en,name_ku,kind',
                'counterpartyCashbox:id,name_ar,name_en,name_ku,kind',
                'actor:id,name,role',
            ])
            ->latest('occurred_at')
            ->latest('id')
            ->limit(250)
            ->get()
            ->map(fn (CashboxVoucher $voucher) => [
                'id' => $voucher->id,
                'reference' => $voucher->reference,
                'type' => $voucher->type,
                'direction' => (int) $voucher->direction,
                'amount' => (int) $voucher->amount,
                'note' => $voucher->note,
                'occurred_at' => $voucher->occurred_at?->toIso8601String(),
                'cashbox' => $voucher->cashbox ? $this->boxPayload($voucher->cashbox) : null,
                'counterparty' => $voucher->counterpartyCashbox ? $this->boxPayload($voucher->counterpartyCashbox) : null,
                'actor' => $voucher->actor ? ['id' => $voucher->actor->id, 'name' => $voucher->actor->name, 'role' => $voucher->actor->role] : null,
            ]);

        return Inertia::render('Admin/Cashboxes', [
            'cashboxes' => $boxes,
            'vouchers' => $vouchers,
            'summary' => [
                'balance' => (int) $boxes->sum('balance'),
                'branch_balance' => (int) $boxes->where('kind', 'branch')->sum('balance'),
                'vault_balance' => (int) $boxes->where('kind', 'vault')->sum('balance'),
                'bank_balance' => (int) $boxes->where('kind', 'bank')->sum('balance'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['vault', 'bank'])],
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'name_ku' => ['nullable', 'string', 'max:120'],
        ]);

        $box = Cashbox::withoutGlobalScope(TenantScope::class)->create([
            ...$data,
            'tenant_id' => Tenant::platform()->id,
            'balance' => 0,
            'is_active' => true,
        ]);

        $this->activity($request, 'cashbox.created', $box->id, ['kind' => $box->kind]);

        return back()->with('success', __('Cashbox created successfully.'));
    }

    public function status(Request $request, Cashbox $cashbox)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $cashbox = Cashbox::withoutGlobalScope(TenantScope::class)->findOrFail($cashbox->id);

        if ($cashbox->branch_id) {
            return back()->withErrors(['cashbox' => __('Branch cashboxes are controlled by the branch status.')]);
        }

        $cashbox->update(['is_active' => (bool) $data['is_active']]);
        $this->activity($request, 'cashbox.status_updated', $cashbox->id, ['is_active' => $cashbox->is_active]);

        return back()->with('success', __('Cashbox status updated.'));
    }

    public function voucher(Request $request, CashboxService $cashboxes)
    {
        $data = $request->validate([
            'cashbox_id' => ['required', 'integer', Rule::exists('cashboxes', 'id')],
            'direction' => ['required', 'integer', Rule::in([-1, 1])],
            'amount' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['receipt', 'payment', 'merchant_settlement'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $cashbox = Cashbox::withoutGlobalScope(TenantScope::class)->findOrFail($data['cashbox_id']);
        $voucher = $cashboxes->record($cashbox, (int) $data['direction'], (int) $data['amount'], $data['type'], $request->user(), $data['note'] ?? null);
        $this->activity($request, 'cashbox.voucher_recorded', $cashbox->id, ['voucher_id' => $voucher->id, 'reference' => $voucher->reference]);

        return back()->with('success', __('Cashbox voucher posted.'));
    }

    public function transfer(Request $request, CashboxService $cashboxes)
    {
        $data = $request->validate([
            'from_cashbox_id' => ['required', 'integer', Rule::exists('cashboxes', 'id')],
            'to_cashbox_id' => ['required', 'integer', 'different:from_cashbox_id', Rule::exists('cashboxes', 'id')],
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $from = Cashbox::withoutGlobalScope(TenantScope::class)->findOrFail($data['from_cashbox_id']);
        $to = Cashbox::withoutGlobalScope(TenantScope::class)->findOrFail($data['to_cashbox_id']);
        $result = $cashboxes->transfer($from, $to, (int) $data['amount'], $request->user(), $data['note'] ?? null);
        $this->activity($request, 'cashbox.transferred', $from->id, ['reference' => $result['out']->reference, 'to_cashbox_id' => $to->id, 'amount' => $data['amount']]);

        return back()->with('success', __('Cashbox transfer posted.'));
    }

    private function boxPayload(Cashbox $box): array
    {
        return [
            'id' => $box->id,
            'kind' => $box->kind,
            'name_ar' => $box->name_ar,
            'name_en' => $box->name_en,
            'name_ku' => $box->name_ku,
            'balance' => (int) $box->balance,
            'is_active' => (bool) $box->is_active,
            'branch' => $box->branch ? [
                'id' => $box->branch->id,
                'name_ar' => $box->branch->name_ar,
                'name_en' => $box->branch->name_en,
                'name_ku' => $box->branch->name_ku,
                'city' => $box->branch->city,
            ] : null,
        ];
    }

    private function activity(Request $request, string $action, int $subjectId, array $data): void
    {
        ActivityLog::create([
            'tenant_id' => Tenant::platform()->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'cashbox',
            'subject_id' => $subjectId,
            'data' => $data,
            'ip' => $request->ip(),
        ]);
    }
}
