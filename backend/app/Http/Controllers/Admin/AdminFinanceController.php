<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FinanceRequest;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\DashboardBranchFilter;
use App\Services\FinanceRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminFinanceController extends Controller
{
    /** @var array<int, string> */
    private const CARD_DETAILS = ['courier_balances', 'delivery_collections', 'qi_topups'];

    private const CARD_DETAIL_LIMIT = 100;

    public function index(Request $request, FinanceRequestService $finance)
    {
        $user = $request->user();
        $scope = $this->branchScope($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
        $canViewFinanceRequests = $user->canUseAdminPermission('finance', 'view_requests');
        $canViewFinanceLedger = $user->canUseAdminPermission('finance', 'view_ledger');
        $canViewFinanceSummary = $user->canUseAdminPermission('finance', 'view_summary');
        $canViewFinanceBalances = $user->canUseAdminPermission('finance', 'view_balances');
        $canApproveFinance = $user->canUseAdminPermission('finance', 'approve');
        $canRejectFinance = $user->canUseAdminPermission('finance', 'reject');
        $canRecordSettlement = $user->canUseAdminPermission('finance', 'record_settlement');
        $canReviewPendingRequests = $canApproveFinance || $canRejectFinance;
        $canUpdateFinance = $canApproveFinance || $canRejectFinance || $canRecordSettlement;

        $request->validate([
            // Card details are deliberately loaded after an operator clicks
            // one card. They can grow without making the initial Finance
            // screen carry every account or every ledger record.
            'detail' => ['nullable', Rule::in(self::CARD_DETAILS)],
        ]);

        if ($request->filled('detail')) {
            abort_unless($request->expectsJson(), 406);

            return response()->json($this->cardDetailPayload(
                $request->string('detail')->toString(),
                $canViewFinanceSummary,
                $canViewFinanceBalances,
                $scope,
                $selectedBranchId,
                $branchFilter,
            ));
        }

        $props = [
            'canUpdateFinance' => $canUpdateFinance,
            'canViewFinanceRequests' => $canViewFinanceRequests,
            'canViewFinanceLedger' => $canViewFinanceLedger,
            'canViewFinanceSummary' => $canViewFinanceSummary,
            'canViewFinanceBalances' => $canViewFinanceBalances,
            'canApproveFinance' => $canApproveFinance,
            'canRejectFinance' => $canRejectFinance,
            'canRecordSettlement' => $canRecordSettlement,
            'branchFilter' => $branchFilter->payload($request, $scope),
        ];

        // A reviewer needs a deliberately bounded pending queue to act on,
        // but must not receive completed/rejected history merely because
        // they can approve or reject a request.
        if ($canReviewPendingRequests) {
            $props['pendingRequests'] = $this->financeRequestRows($scope, $selectedBranchId, $branchFilter, FinanceRequest::PENDING);
        }

        if ($canViewFinanceRequests) {
            $props['requests'] = $this->financeRequestRows($scope, $selectedBranchId, $branchFilter);
        }

        if ($canViewFinanceLedger) {
            $props['transactions'] = $this->financeLedgerRows($scope, $selectedBranchId, $branchFilter);
        }

        // Aggregates and the drill-down cards are one read boundary. A
        // ledger reader can inspect journal rows, for example, without also
        // learning platform-wide totals.
        if ($canViewFinanceSummary) {
            $props['summary'] = $this->financeSummary($scope, $selectedBranchId, $branchFilter);
        }

        // Balances are distinct from historic requests, the ledger, and the
        // aggregated overview. Keep the balance card contract separate so a
        // balance-only operator never receives unrelated finance totals.
        if ($canViewFinanceBalances) {
            $branchCash = Branch::withoutGlobalScope(TenantScope::class);

            if ($scope->hasBranchScope()) {
                $scope->restrict($branchCash, 'branches.id');
            } else {
                $branchFilter->restrictByColumn($branchCash, $selectedBranchId, 'branches.id');
            }

            $props['balanceSummary'] = [
                'branch_cash' => (int) $branchCash->sum('cash_balance'),
                'courier_balances' => $this->courierBalanceCardSummary($scope, $selectedBranchId, $branchFilter),
            ];
        }

        if ($canViewFinanceBalances || $canApproveFinance || $canRecordSettlement) {
            $props['branches'] = $this->activeBranchDirectory($scope, $selectedBranchId, $branchFilter, $canViewFinanceBalances);
        }

        if ($canViewFinanceBalances || $canRecordSettlement) {
            $props['accounts'] = $this->activeSettlementAccountDirectory($scope, $selectedBranchId, $branchFilter, $canViewFinanceBalances, $finance);
        }

        return Inertia::render('Admin/Finance', $props);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function financeRequestRows(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter, ?string $status = null): \Illuminate\Support\Collection
    {
        $requests = FinanceRequest::withoutGlobalScope(TenantScope::class)
            ->with(['user:id,name,phone,role', 'branch:id,name_ar,city', 'processor:id,name'])
            ->latest('id');

        $this->scopeFinanceRequests($requests, $scope, $selectedBranchId, $branchFilter);

        if ($status !== null) {
            $requests->where('status', $status);
        }

        return $requests
            ->limit(150)
            ->get()
            ->map(fn (FinanceRequest $financeRequest) => $this->requestData($financeRequest));
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, int|string|null>>
     */
    private function financeLedgerRows(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): \Illuminate\Support\Collection
    {
        return $this->financeLedgerQuery($scope, $selectedBranchId, $branchFilter)
            ->with(['user:id,name,role', 'financeRequest:id,reference,type'])
            ->latest('date')
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (int) $tx->amount,
                'direction' => (int) $tx->direction,
                'ref' => $tx->ref,
                'date' => $tx->date?->toDateString(),
                'note' => $tx->note,
                'user' => $tx->user?->name,
                'role' => $tx->user?->role,
                'request_ref' => $tx->financeRequest?->reference,
            ]);
    }

    /**
     * A journal row has no direct branch key. Requests are authoritative when
     * present; standalone rows are limited through their operational order or
     * local account. This deliberately prevents a moved user from making an
     * old, foreign finance request visible through the user relation alone.
     *
     * @return Builder<Transaction>
     */
    private function financeLedgerQuery(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): Builder
    {
        $transactions = Transaction::withoutGlobalScope(TenantScope::class);

        if (! $scope->hasBranchScope() && $selectedBranchId === null) {
            return $transactions;
        }

        return $transactions->where(function (Builder $ledger) use ($scope, $selectedBranchId, $branchFilter): void {
            $ledger
                ->whereHas('financeRequest', function (Builder $requests) use ($scope, $selectedBranchId, $branchFilter): void {
                    $this->scopeFinanceRequests(
                        $requests->withoutGlobalScope(TenantScope::class),
                        $scope,
                        $selectedBranchId,
                        $branchFilter,
                    );
                })
                ->orWhere(function (Builder $standalone) use ($scope, $selectedBranchId, $branchFilter): void {
                    $standalone
                        ->whereNull('finance_request_id')
                        ->where(function (Builder $unlinked) use ($scope, $selectedBranchId, $branchFilter): void {
                            $unlinked
                                ->whereHas('order', function (Builder $orders) use ($scope, $selectedBranchId, $branchFilter): void {
                                    $orders->withoutGlobalScope(TenantScope::class);
                                    if ($scope->hasBranchScope()) {
                                        $scope->restrictOrders($orders);
                                    } else {
                                        $branchFilter->restrictOrders($orders, $selectedBranchId);
                                    }
                                })
                                ->orWhere(function (Builder $accountRows) use ($scope, $selectedBranchId, $branchFilter): void {
                                    $accountRows
                                        ->whereNull('order_id')
                                        ->whereHas('user', function (Builder $users) use ($scope, $selectedBranchId, $branchFilter): void {
                                            $users->withoutGlobalScopes();
                                            $this->restrictUsersToBranch($users, $scope, $selectedBranchId, $branchFilter);
                                        });
                                });
                        });
                });
        });
    }

    /**
     * A local finance queue must be local on both dimensions: the account
     * belongs to this branch and any explicit settlement branch is this
     * branch too. This deliberately prefers strict isolation over showing a
     * historical row after an account is reassigned; the platform owner can
     * still audit the complete history.
     *
     * @param  Builder<FinanceRequest>  $requests
     * @return Builder<FinanceRequest>
     */
    private function scopeFinanceRequests(Builder $requests, BranchDashboardScope $scope, ?int $selectedBranchId = null, ?DashboardBranchFilter $branchFilter = null): Builder
    {
        $branchId = $scope->hasBranchScope() ? $scope->branchId() : $selectedBranchId;

        if ($branchId === null) {
            return $requests;
        }

        return $requests
            ->whereHas('user', function (Builder $users) use ($scope, $selectedBranchId, $branchFilter): void {
                $users->withoutGlobalScopes();
                $this->restrictUsersToBranch($users, $scope, $selectedBranchId, $branchFilter);
            })
            ->where(function (Builder $visible) use ($branchId): void {
                $visible
                    ->where('finance_requests.branch_id', $branchId)
                    ->orWhereNull('finance_requests.branch_id');
            });
    }

    /** @param Builder<User> $users */
    private function restrictUsersToBranch(Builder $users, BranchDashboardScope $scope, ?int $selectedBranchId, ?DashboardBranchFilter $branchFilter): Builder
    {
        if ($scope->hasBranchScope()) {
            return $scope->restrictUsers($users);
        }

        return $branchFilter?->restrictByColumn($users, $selectedBranchId, 'users.branch_id') ?? $users;
    }

    /** @return array<string, int|array{count: int, amount: int}> */
    private function financeSummary(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): array
    {
        $deliveryCollections = $this->transactionCardSummary($scope, $selectedBranchId, $branchFilter, 'collected');
        $qiTopups = $this->transactionCardSummary($scope, $selectedBranchId, $branchFilter, FinanceRequest::QI_TOPUP);

        $pendingRequests = FinanceRequest::withoutGlobalScope(TenantScope::class)
            ->where('status', FinanceRequest::PENDING);

        $this->scopeFinanceRequests($pendingRequests, $scope, $selectedBranchId, $branchFilter);

        return [
            'pending_count' => (clone $pendingRequests)->count(),
            'pending_amount' => (int) (clone $pendingRequests)->sum('amount'),
            'pending_qi_count' => (clone $pendingRequests)
                ->where('type', FinanceRequest::QI_TOPUP)
                ->count(),
            'cash_handover' => (int) $this->financeLedgerQuery($scope, $selectedBranchId, $branchFilter)
                ->where('type', FinanceRequest::CASH_HANDOVER)
                ->where('direction', -1)
                ->sum('amount'),
            'budget_added' => (int) $this->financeLedgerQuery($scope, $selectedBranchId, $branchFilter)
                ->where('type', FinanceRequest::BUDGET_RECHARGE)
                ->where('direction', 1)
                ->sum('amount'),
            'qi_topups' => $qiTopups['amount'],
            'merchant_payouts' => (int) $this->financeLedgerQuery($scope, $selectedBranchId, $branchFilter)
                ->where('type', FinanceRequest::MERCHANT_PAYOUT)
                ->where('direction', -1)
                ->sum('amount'),
            'cards' => [
                // `collected` is the immutable net delivery-income posting
                // (fee after the administration deduction). A cash handover
                // is only a later physical transfer to a branch.
                'delivery_collections' => $deliveryCollections,
                'qi_topups' => $qiTopups,
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, int|string|null>>
     */
    private function activeBranchDirectory(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter, bool $includeBalances): \Illuminate\Support\Collection
    {
        $branches = Branch::withoutGlobalScope(TenantScope::class)
            ->where('is_active', true)
            ->orderBy('city')
            ->orderBy('name_ar');

        if ($scope->hasBranchScope()) {
            $scope->restrict($branches, 'branches.id');
        } else {
            $branchFilter->restrictByColumn($branches, $selectedBranchId, 'branches.id');
        }

        return $branches
            ->get($includeBalances ? ['id', 'name_ar', 'city', 'cash_balance'] : ['id', 'name_ar', 'city'])
            ->map(function (Branch $branch) use ($includeBalances): array {
                $directoryEntry = [
                    'id' => $branch->id,
                    'name' => $branch->name_ar,
                    'city' => $branch->city,
                ];

                if ($includeBalances) {
                    $directoryEntry['cash_balance'] = (int) $branch->cash_balance;
                }

                return $directoryEntry;
            });
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, int|string|null>>
     */
    private function activeSettlementAccountDirectory(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter, bool $includeBalances, FinanceRequestService $finance): \Illuminate\Support\Collection
    {
        $accounts = User::withoutGlobalScopes()
            ->whereIn('role', ['merchant', 'courier'])
            ->where('status', 'active');

        $this->restrictUsersToBranch($accounts, $scope, $selectedBranchId, $branchFilter);

        if ($includeBalances) {
            $accounts->with('wallet:user_id,balance,budget,budget_balance');
        }

        return $accounts
            ->orderBy('role')
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'phone', 'role'])
            ->map(function (User $account) use ($includeBalances, $finance): array {
                $directoryEntry = [
                    'id' => $account->id,
                    'name' => $account->name,
                    'phone' => $account->phone,
                    'role' => $account->role,
                ];

                if (! $includeBalances) {
                    return $directoryEntry;
                }

                return [
                    ...$directoryEntry,
                    'wallet_balance' => (int) ($account->wallet?->balance ?? 0),
                    'budget' => (int) ($account->wallet?->budget ?? 0),
                    'budget_balance' => (int) ($account->wallet?->budget_balance ?? 0),
                    'cash_on_hand' => $account->role === 'courier' ? $finance->cashOnHand($account->id) : null,
                    'collections_total' => $account->role === 'courier' ? $finance->collectionsTotal($account) : null,
                ];
            });
    }

    /**
     * Return a bounded JSON dataset for one clickable finance summary card.
     *
     * @return array{detail: string, rows: array<int, array<string, mixed>>, total: int, limit: int, truncated: bool}
     */
    private function cardDetailPayload(string $detail, bool $canViewFinanceSummary, bool $canViewFinanceBalances, BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): array
    {
        if ($detail === 'courier_balances') {
            abort_unless($canViewFinanceBalances, 403);

            $payload = $this->courierBalanceDetailPayload($scope, $selectedBranchId, $branchFilter);
        } else {
            // Card JSON includes account identities and amounts, so it must
            // use the same explicit grant as the summary that links to it.
            abort_unless($canViewFinanceSummary, 403);

            $payload = $this->transactionDetailPayload(
                $scope,
                $selectedBranchId,
                $branchFilter,
                $detail === 'delivery_collections' ? 'collected' : FinanceRequest::QI_TOPUP,
            );
        }

        return [
            'detail' => $detail,
            ...$payload,
            'limit' => self::CARD_DETAIL_LIMIT,
            'truncated' => $payload['total'] > self::CARD_DETAIL_LIMIT,
        ];
    }

    /** @return array{count: int, amount: int} */
    private function transactionCardSummary(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter, string $type): array
    {
        $summary = $this->financeLedgerQuery($scope, $selectedBranchId, $branchFilter)
            ->where('type', $type)
            ->where('direction', 1)
            ->selectRaw('COUNT(*) AS card_count, COALESCE(SUM(amount), 0) AS card_amount')
            ->first();

        return [
            'count' => (int) ($summary->card_count ?? 0),
            'amount' => (int) ($summary->card_amount ?? 0),
        ];
    }

    /** @return array{count: int, amount: int} */
    private function courierBalanceCardSummary(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): array
    {
        $accounts = User::withoutGlobalScopes()
            ->where('users.role', 'courier')
            ->where('users.status', 'active');

        $this->restrictUsersToBranch($accounts, $scope, $selectedBranchId, $branchFilter);

        $summary = $accounts
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->selectRaw('COUNT(users.id) AS card_count, COALESCE(SUM(COALESCE(wallets.balance, 0)), 0) AS card_amount')
            ->first();

        return [
            'count' => (int) ($summary->card_count ?? 0),
            // This is Qi credit only. Cash budget values are shown per
            // courier in the protected detail, never folded into this total.
            'amount' => (int) ($summary->card_amount ?? 0),
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    private function courierBalanceDetailPayload(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter): array
    {
        $accounts = User::withoutGlobalScopes()
            ->where('users.role', 'courier')
            ->where('users.status', 'active');

        $this->restrictUsersToBranch($accounts, $scope, $selectedBranchId, $branchFilter);
        $total = (clone $accounts)->count();

        $rows = $accounts
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->orderByDesc('wallets.balance')
            ->orderBy('users.name')
            ->limit(self::CARD_DETAIL_LIMIT)
            ->get([
                'users.id',
                'users.name',
                'users.phone',
                'wallets.balance as wallet_balance',
                'wallets.budget as budget',
                'wallets.budget_balance as budget_balance',
            ])
            ->map(fn (User $courier) => [
                'id' => $courier->id,
                'name' => $courier->name,
                'phone' => $courier->phone,
                'wallet_balance' => (int) ($courier->wallet_balance ?? 0),
                'budget' => (int) ($courier->budget ?? 0),
                'budget_balance' => (int) ($courier->budget_balance ?? 0),
            ])->values()->all();

        return compact('rows', 'total');
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    private function transactionDetailPayload(BranchDashboardScope $scope, ?int $selectedBranchId, DashboardBranchFilter $branchFilter, string $type): array
    {
        $transactions = $this->financeLedgerQuery($scope, $selectedBranchId, $branchFilter)
            ->where('type', $type)
            ->where('direction', 1);
        $total = (clone $transactions)->count();

        $rows = $transactions
            ->with([
                'user:id,name,phone,role',
                'financeRequest:id,reference,external_reference',
            ])
            ->latest('date')
            ->latest('id')
            ->limit(self::CARD_DETAIL_LIMIT)
            ->get()
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'amount' => (int) $transaction->amount,
                'date' => $transaction->date?->toDateString(),
                'reference' => $transaction->financeRequest?->reference ?: $transaction->ref,
                'external_reference' => $transaction->financeRequest?->external_reference,
                'order_id' => $transaction->order_id,
                'note' => $transaction->note,
                'courier' => $transaction->user ? [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                    'phone' => $transaction->user->phone,
                ] : null,
            ])->values()->all();

        return compact('rows', 'total');
    }

    public function approve(Request $request, FinanceRequest $financeRequest, FinanceRequestService $finance)
    {
        $scope = $this->branchScope($request);
        $data = $request->validate([
            'approved_amount' => ['required', 'integer', 'min:1000'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $financeRequest = $this->scopedFinanceRequest($financeRequest, $scope);

        if ($scope->hasBranchScope() && isset($data['branch_id'])) {
            abort_unless($scope->allowsBranch((int) $data['branch_id']), 403);
        }

        $finance->approve(
            $financeRequest->id,
            $request->user(),
            (int) $data['approved_amount'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            $data['decision_note'] ?? null,
        );

        return back()->with('success', __('Finance request approved and posted to the ledger.'));
    }

    public function reject(Request $request, FinanceRequest $financeRequest, FinanceRequestService $finance)
    {
        $scope = $this->branchScope($request);
        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $financeRequest = $this->scopedFinanceRequest($financeRequest, $scope);

        $finance->reject($financeRequest->id, $request->user(), $data['decision_note'] ?? null);

        return back()->with('success', __('Finance request rejected.'));
    }

    /**
     * The office can enter a cash handover, recharge, or merchant settlement
     * received in person.  It still creates the same request and ledger
     * record as a mobile request, so the audit trail never has a backdoor.
     */
    public function recordSettlement(Request $request, FinanceRequestService $finance)
    {
        $scope = $this->branchScope($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['merchant', 'courier']))],
            'type' => ['required', Rule::in(FinanceRequest::TYPES)],
            'amount' => ['required', 'integer', 'min:1000'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

        if ($scope->hasBranchScope()) {
            if ($branchId !== null) {
                abort_unless($scope->allowsBranch($branchId), 403);
            }

            // Local cash settlement must be tied to the server-resolved
            // branch, never to a browser-selected branch id.
            $branchId = $scope->branchId();
        }

        $accounts = User::withoutGlobalScopes();

        if ($scope->hasBranchScope()) {
            $scope->restrictUsers($accounts);
        }

        $account = $accounts->findOrFail($data['user_id']);
        $requestType = $data['type'];

        if ($requestType === FinanceRequest::CASH_HANDOVER && $account->role !== 'courier') {
            return back()->withErrors(['user_id' => __('Cash handover must belong to a courier account.')]);
        }
        if ($requestType === FinanceRequest::BUDGET_RECHARGE && $account->role !== 'courier') {
            return back()->withErrors(['user_id' => __('Cash budget additions must belong to a courier account.')]);
        }
        if ($requestType === FinanceRequest::QI_TOPUP && $account->role !== 'courier') {
            return back()->withErrors(['user_id' => __('Qi balance top-ups must belong to a courier account.')]);
        }
        if ($requestType === FinanceRequest::MERCHANT_PAYOUT && $account->role !== 'merchant') {
            return back()->withErrors(['user_id' => __('Merchant payout must belong to a merchant account.')]);
        }

        $financeRequest = $finance->submit(
            $account,
            $requestType,
            (int) $data['amount'],
            $branchId,
            $data['note'] ?? null,
            filled($data['external_reference'] ?? null) ? trim((string) $data['external_reference']) : null,
        );

        $finance->approve(
            $financeRequest->id,
            $request->user(),
            (int) $data['amount'],
            $branchId,
            $data['note'] ?? null,
        );

        return back()->with('success', __('Settlement recorded and posted to the ledger.'));
    }

    private function scopedFinanceRequest(FinanceRequest $financeRequest, BranchDashboardScope $scope): FinanceRequest
    {
        if (! $scope->hasBranchScope()) {
            return $financeRequest;
        }

        $requests = FinanceRequest::withoutGlobalScope(TenantScope::class)
            ->whereKey($financeRequest->getKey());

        $this->scopeFinanceRequests($requests, $scope);

        if ($scope->hasBranchScope()) {
            // An explicit branch id on an old or malformed request is not
            // enough to authorise a mutation. Approval/rejection must also
            // belong to a currently local account, so a branch manager
            // cannot process a foreign courier through a forged handover.
            $requests->whereHas('user', function (Builder $users) use ($scope): void {
                $scope->restrictUsers($users->withoutGlobalScopes());
            });
        }

        return $requests->firstOrFail();
    }

    private function branchScope(Request $request): BranchDashboardScope
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);

        if ($scope->requiresBranchScope() && ! $scope->isAvailable()) {
            abort(403);
        }

        return $scope;
    }

    private function requestData(FinanceRequest $request): array
    {
        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'type' => $request->type,
            'status' => $request->status,
            'amount' => (int) $request->amount,
            'approved_amount' => $request->approved_amount !== null ? (int) $request->approved_amount : null,
            'external_reference' => $request->external_reference,
            'note' => $request->note,
            'decision_note' => $request->decision_note,
            'created_at' => $request->created_at?->toIso8601String(),
            'processed_at' => $request->processed_at?->toIso8601String(),
            'user' => $request->user ? [
                'id' => $request->user->id,
                'name' => $request->user->name,
                'phone' => $request->user->phone,
                'role' => $request->user->role,
            ] : null,
            'branch' => $request->branch ? [
                'id' => $request->branch->id,
                'name' => $request->branch->name_ar,
                'city' => $request->branch->city,
            ] : null,
            'processor' => $request->processor?->name,
        ];
    }
}
