<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cashbox;
use App\Models\CashboxVoucher;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\CashboxService;
use App\Services\DashboardBranchFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminCashboxController extends Controller
{
    public function index(Request $request, CashboxService $cashboxes)
    {
        $user = $request->user();
        $scope = $this->branchScope($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
        $canViewCashboxBalances = $user->canUseAdminPermission('cashboxes', 'view_balances');
        $canViewCashboxLedger = $user->canUseAdminPermission('cashboxes', 'view_ledger');

        // Opening a dashboard page must always be read-only.  In particular,
        // do not call ensureOperatingCashboxes() here: a view-only employee
        // must never create branch, vault, or bank records merely by loading
        // the directory.
        $cashboxQuery = Cashbox::withoutGlobalScope(TenantScope::class)
            ->select([
                'id',
                'branch_id',
                'kind',
                'name_ar',
                'name_en',
                'name_ku',
                'is_active',
            ])
            ->with('branch:id,name_ar,name_en,name_ku,city')
            ->where('tenant_id', Tenant::platform()->id)
            // ANSI CASE keeps the operating order portable across the
            // production MySQL database and SQLite used by automated tests.
            ->orderByRaw("CASE kind WHEN 'branch' THEN 1 WHEN 'vault' THEN 2 WHEN 'bank' THEN 3 ELSE 4 END")
            ->orderBy('name_ar');

        if ($canViewCashboxBalances) {
            // Do not even select the historical cached balance for a
            // directory-only or ledger-only operator.
            $cashboxQuery->addSelect('balance');
        }

        if ($scope->hasBranchScope()) {
            $scope->restrict($cashboxQuery, 'cashboxes.branch_id');
        } else {
            $branchFilter->restrictByColumn($cashboxQuery, $selectedBranchId, 'cashboxes.branch_id');
        }

        $cashboxModels = $cashboxQuery->get();

        $collectionBalances = $canViewCashboxBalances
            ? $cashboxes->collectionBalances($cashboxModels)
            : [];
        $boxes = $cashboxModels->map(fn (Cashbox $box) => $this->boxPayload(
            $box,
            $canViewCashboxBalances ? ($collectionBalances[$box->id] ?? 0) : null,
            $canViewCashboxBalances,
        ));

        $vouchers = collect();

        if ($canViewCashboxLedger) {
            $voucherQuery = CashboxVoucher::withoutGlobalScope(TenantScope::class)
                // Collection reporting deliberately excludes historical/manual
                // receipts, payments, and opening balances.  New rows can only
                // be courier handovers or custody transfers of those handovers.
                ->where('tenant_id', Tenant::platform()->id)
                ->whereIn('type', CashboxVoucher::COLLECTION_CUSTODY_TYPES);

            if ($scope->hasBranchScope()) {
                $scope->restrict($voucherQuery, 'cashbox_vouchers.branch_id');
            } else {
                $branchFilter->restrictByColumn($voucherQuery, $selectedBranchId, 'cashbox_vouchers.branch_id');
            }

            $vouchers = $voucherQuery
                ->with([
                    'cashbox:id,branch_id,name_ar,name_en,name_ku,kind,is_active',
                    'counterpartyCashbox:id,branch_id,name_ar,name_en,name_ku,kind,is_active',
                    'cashbox.branch:id,name_ar,name_en,name_ku,city',
                    'counterpartyCashbox.branch:id,name_ar,name_en,name_ku,city',
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
                    // Ledger permission reveals individual entries, not the
                    // separate current balance or its historical cache.
                    'cashbox' => $this->visibleBoxPayload($voucher->cashbox, $scope, $selectedBranchId),
                    'counterparty' => $this->visibleBoxPayload($voucher->counterpartyCashbox, $scope, $selectedBranchId),
                    'actor' => $voucher->actor ? ['id' => $voucher->actor->id, 'name' => $voucher->actor->name, 'role' => $voucher->actor->role] : null,
                ]);
        }

        $summary = [
            // These counts are deliberately non-financial and remain useful
            // to a directory-only operator.
            'cashboxes_count' => $boxes->count(),
            'branch_cashboxes_count' => $boxes->where('kind', 'branch')->count(),
            'active_cashboxes_count' => $boxes->where('is_active', true)->count(),
        ];

        if ($canViewCashboxBalances) {
            $summary += [
                'balance' => (int) $boxes->sum('balance'),
                'branch_balance' => (int) $boxes->where('kind', 'branch')->sum('balance'),
                'vault_balance' => (int) $boxes->where('kind', 'vault')->sum('balance'),
                'bank_balance' => (int) $boxes->where('kind', 'bank')->sum('balance'),
            ];
        }

        return Inertia::render('Admin/Cashboxes', [
            'cashboxes' => $boxes,
            'vouchers' => $vouchers,
            'summary' => $summary,
            'collection_scope' => 'courier_delivery_collections_only',
            'canViewCashboxBalances' => $canViewCashboxBalances,
            'canViewCashboxLedger' => $canViewCashboxLedger,
            'canCreateCashboxes' => $user->canUseAdminPermission('cashboxes', 'create'),
            'canTransferCashboxCollections' => $user->canUseAdminPermission('cashboxes', 'transfer'),
            'canChangeCashboxStatus' => $user->canUseAdminPermission('cashboxes', 'change_status'),
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    public function store(Request $request)
    {
        $scope = $this->branchScope($request);

        // A branch manager has exactly one local branch cashbox; vault and
        // bank boxes are platform-wide records and cannot be created locally.
        abort_if($scope->hasBranchScope(), 403);

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
        $scope = $this->branchScope($request);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $cashboxes = Cashbox::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', Tenant::platform()->id);

        if ($scope->hasBranchScope()) {
            $scope->restrict($cashboxes, 'cashboxes.branch_id');
        }

        $cashbox = $cashboxes->findOrFail($cashbox->id);

        if ($cashbox->branch_id) {
            return back()->withErrors(['cashbox' => __('Branch cashboxes are controlled by the branch status.')]);
        }

        $cashbox->update(['is_active' => (bool) $data['is_active']]);
        $this->activity($request, 'cashbox.status_updated', $cashbox->id, ['is_active' => $cashbox->is_active]);

        return back()->with('success', __('Cashbox status updated.'));
    }

    /**
     * Intentionally preserved as a safe route for older built clients.  A
     * cashbox may receive money only through the approved courier-handover
     * workflow, never through a dashboard-entered receipt/payment voucher.
     */
    public function voucher(Request $request)
    {
        $this->branchScope($request);

        return back()->withErrors([
            'cashbox' => 'الصناديق تعرض تحصيلات إيرادات التوصيل المعتمدة فقط؛ لا تتوفر سندات قبض أو صرف يدوية.',
        ]);
    }

    public function transfer(Request $request, CashboxService $cashboxes)
    {
        $scope = $this->branchScope($request);
        $data = $request->validate([
            'from_cashbox_id' => ['required', 'integer', Rule::exists('cashboxes', 'id')],
            'to_cashbox_id' => ['required', 'integer', 'different:from_cashbox_id', Rule::exists('cashboxes', 'id')],
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Creating central operating records is a platform operation. A
        // branch account may only address pre-existing cashboxes in its own
        // branch and must never cause vault/bank records for other branches to
        // be materialised as a side effect of a request.
        if (! $scope->hasBranchScope()) {
            $cashboxes->ensureOperatingCashboxes();
        }

        $from = $this->scopedCashbox((int) $data['from_cashbox_id'], $scope);
        $to = $this->scopedCashbox((int) $data['to_cashbox_id'], $scope);
        $result = $cashboxes->transfer($from, $to, (int) $data['amount'], $request->user(), $data['note'] ?? null);
        $this->activity($request, 'cashbox.transferred', $from->id, ['reference' => $result['out']->reference, 'to_cashbox_id' => $to->id, 'amount' => $data['amount']]);

        return back()->with('success', __('Cashbox transfer posted.'));
    }

    private function boxPayload(Cashbox $box, ?int $collectionBalance = null, bool $includeBalances = false): array
    {
        $payload = [
            'id' => $box->id,
            'kind' => $box->kind,
            'name_ar' => $box->name_ar,
            'name_en' => $box->name_en,
            'name_ku' => $box->name_ku,
            'is_active' => (bool) $box->is_active,
            'branch' => $box->branch ? [
                'id' => $box->branch->id,
                'name_ar' => $box->branch->name_ar,
                'name_en' => $box->branch->name_en,
                'name_ku' => $box->branch->name_ku,
                'city' => $box->branch->city,
            ] : null,
        ];

        if ($includeBalances) {
            // `balance` is the collection-only value used by the dashboard.
            // Keep the historical cached amount available only to the
            // dedicated balance reader so old manual records are neither
            // deleted nor mixed into revenue.
            $payload += [
                'balance' => $collectionBalance ?? (int) $box->balance,
                'historical_book_balance' => (int) $box->balance,
                'balance_source' => 'courier_delivery_collections_only',
            ];
        }

        return $payload;
    }

    private function visibleBoxPayload(?Cashbox $box, BranchDashboardScope $scope, ?int $selectedBranchId = null): ?array
    {
        if (! $box
            || ($scope->hasBranchScope() && ! $scope->allowsBranch((int) $box->branch_id))
            || (! $scope->hasBranchScope() && $selectedBranchId !== null && (int) $box->branch_id !== $selectedBranchId)) {
            return null;
        }

        return $this->boxPayload($box);
    }

    private function scopedCashbox(int $cashboxId, BranchDashboardScope $scope): Cashbox
    {
        $cashboxes = Cashbox::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', Tenant::platform()->id)
            ->whereKey($cashboxId);

        if ($scope->hasBranchScope()) {
            $scope->restrict($cashboxes, 'cashboxes.branch_id');
        }

        return $cashboxes->firstOrFail();
    }

    private function branchScope(Request $request): BranchDashboardScope
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);

        if ($scope->requiresBranchScope() && ! $scope->isAvailable()) {
            abort(403);
        }

        return $scope;
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
