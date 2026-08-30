<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FinanceRequest;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminFinanceController extends Controller
{
    public function index(Request $request, FinanceRequestService $finance)
    {
        $canUpdateFinance = $request->user()->canUseAdminPermission('finance', 'update');

        $requests = FinanceRequest::withoutGlobalScope(TenantScope::class)
            ->with(['user:id,name,phone,role', 'branch:id,name_ar,city', 'processor:id,name'])
            ->latest('id')
            ->limit(150)
            ->get()
            ->map(fn (FinanceRequest $request) => $this->requestData($request));

        $transactions = Transaction::withoutGlobalScope(TenantScope::class)
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

        $props = [
            'requests' => $requests,
            'transactions' => $transactions,
            'canUpdateFinance' => $canUpdateFinance,
            'summary' => [
                'pending_count' => FinanceRequest::withoutGlobalScope(TenantScope::class)->where('status', FinanceRequest::PENDING)->count(),
                'pending_amount' => (int) FinanceRequest::withoutGlobalScope(TenantScope::class)->where('status', FinanceRequest::PENDING)->sum('amount'),
                'cash_handover' => (int) Transaction::withoutGlobalScope(TenantScope::class)->where('type', FinanceRequest::CASH_HANDOVER)->where('direction', -1)->sum('amount'),
                'budget_added' => (int) Transaction::withoutGlobalScope(TenantScope::class)->where('type', FinanceRequest::BUDGET_RECHARGE)->where('direction', 1)->sum('amount'),
                'qi_topups' => (int) Transaction::withoutGlobalScope(TenantScope::class)->where('type', FinanceRequest::QI_TOPUP)->where('direction', 1)->sum('amount'),
                'merchant_payouts' => (int) Transaction::withoutGlobalScope(TenantScope::class)->where('type', FinanceRequest::MERCHANT_PAYOUT)->where('direction', -1)->sum('amount'),
                'branch_cash' => (int) Branch::withoutGlobalScope(TenantScope::class)->sum('cash_balance'),
            ],
        ];

        // A finance-view profile can audit requests and ledger rows, but it
        // must not receive the platform-wide settlement directory or each
        // account's wallet, budget, and cash-on-hand amounts.
        if ($canUpdateFinance) {
            $props['branches'] = Branch::withoutGlobalScope(TenantScope::class)
                ->where('is_active', true)
                ->orderBy('city')
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'city', 'cash_balance'])
                ->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name_ar,
                    'city' => $branch->city,
                    'cash_balance' => (int) $branch->cash_balance,
                ]);

            $props['accounts'] = User::withoutGlobalScopes()
                ->whereIn('role', ['merchant', 'courier'])
                ->where('status', 'active')
                ->with('wallet:user_id,balance,budget')
                ->orderBy('role')
                ->orderBy('name')
                ->get(['id', 'tenant_id', 'name', 'phone', 'role'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'wallet_balance' => (int) ($user->wallet?->balance ?? 0),
                    'budget' => (int) ($user->wallet?->budget ?? 0),
                    'cash_on_hand' => $user->isCourierRole() ? $finance->cashOnHand($user->id) : null,
                    'collections_total' => $user->isCourierRole() ? $finance->collectionsTotal($user) : null,
                ]);
        }

        return Inertia::render('Admin/Finance', $props);
    }

    public function approve(Request $request, FinanceRequest $financeRequest, FinanceRequestService $finance)
    {
        $data = $request->validate([
            'approved_amount' => ['required', 'integer', 'min:1000'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

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
        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

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
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['merchant', ...User::COURIER_ROLES]))],
            'type' => ['required', Rule::in(FinanceRequest::TYPES)],
            'amount' => ['required', 'integer', 'min:1000'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $account = User::withoutGlobalScopes()->findOrFail($data['user_id']);
        $requestType = $data['type'];

        if ($requestType === FinanceRequest::CASH_HANDOVER && ! $account->isCourierRole()) {
            return back()->withErrors(['user_id' => __('Cash handover must belong to a courier account.')]);
        }
        if ($requestType === FinanceRequest::BUDGET_RECHARGE && ! $account->isCourierRole()) {
            return back()->withErrors(['user_id' => __('Cash budget additions must belong to a courier account.')]);
        }
        if ($requestType === FinanceRequest::QI_TOPUP && ! $account->isCourierRole()) {
            return back()->withErrors(['user_id' => __('Qi balance top-ups must belong to a courier account.')]);
        }
        if ($requestType === FinanceRequest::MERCHANT_PAYOUT && $account->role !== 'merchant') {
            return back()->withErrors(['user_id' => __('Merchant payout must belong to a merchant account.')]);
        }

        $financeRequest = $finance->submit(
            $account,
            $requestType,
            (int) $data['amount'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            $data['note'] ?? null,
            filled($data['external_reference'] ?? null) ? trim((string) $data['external_reference']) : null,
        );

        $finance->approve(
            $financeRequest->id,
            $request->user(),
            (int) $data['amount'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            $data['note'] ?? null,
        );

        return back()->with('success', __('Settlement recorded and posted to the ledger.'));
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
