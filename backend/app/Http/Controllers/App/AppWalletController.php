<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FinanceRequest;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\CourierOrderAccess;
use App\Services\FinanceRequestService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AppWalletController extends Controller
{
    public function index(Request $request, FinanceRequestService $finance)
    {
        $user = $request->user();
        $wallet = $user->wallet ?: Wallet::create(['user_id' => $user->id, 'balance' => 0, 'budget' => 0]);
        $isCourier = $user->isCourierRole();

        $ledger = Transaction::query()
            ->where('user_id', $user->id)
            ->latest('date')
            ->latest('id');

        $transactions = (clone $ledger)
            ->limit(50)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (int) $tx->amount,
                'direction' => (int) $tx->direction,
                'ref' => $tx->ref,
                'date' => $tx->date?->toDateString(),
                'note' => $tx->note,
            ]);

        $requests = FinanceRequest::query()
            ->with('branch:id,name_ar,city')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (FinanceRequest $financeRequest) => [
                'id' => $financeRequest->id,
                'reference' => $financeRequest->reference,
                'type' => $financeRequest->type,
                'status' => $financeRequest->status,
                'amount' => (int) $financeRequest->amount,
                'approved_amount' => $financeRequest->approved_amount !== null ? (int) $financeRequest->approved_amount : null,
                'note' => $financeRequest->note,
                'decision_note' => $financeRequest->decision_note,
                'created_at' => $financeRequest->created_at?->toIso8601String(),
                'branch' => $financeRequest->branch ? [
                    'id' => $financeRequest->branch->id,
                    'name' => $financeRequest->branch->name_ar,
                    'city' => $financeRequest->branch->city,
                ] : null,
            ]);

        $branches = $isCourier
            ? Branch::withoutGlobalScopes()
                ->where('is_active', true)
                ->orderBy('city')
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'city'])
                ->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name_ar,
                    'city' => $branch->city,
                ])
            : collect();

        $loyaltyAccount = $user->loyaltyAccount;
        $loyaltyEntries = $loyaltyAccount
            ? $loyaltyAccount->entries()->limit(8)->get()->map(fn ($entry) => [
                'id' => $entry->id,
                'points' => (int) $entry->points,
                'balance_after' => (int) $entry->balance_after,
                'type' => $entry->type,
                'note' => $entry->note,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values()
            : collect();

        return Inertia::render('Mobile/Wallet', [
            'isCourier' => $isCourier,
            // A courier's cash position is derived from delivered orders and
            // approved handovers, not an editable/demo wallet balance.
            'balance' => $isCourier ? $finance->cashOnHand($user->id) : (int) $wallet->balance,
            'budget' => (int) $wallet->budget,
            'transactions' => $transactions,
            'requests' => $requests,
            'branches' => $branches,
            // Loyalty is intentionally a separate, non-monetary balance. It
            // is never added to the cash wallet or finance request totals.
            'loyalty' => [
                'balance' => (int) ($loyaltyAccount?->balance ?? 0),
                'entries' => $loyaltyEntries,
            ],
            'summary' => $isCourier
                ? $this->courierSummary($user, $finance)
                : $this->merchantSummary($ledger, $wallet),
        ]);
    }

    /**
     * Merchant figures are entirely based on persisted orders and the ledger.
     * A payout leaves the merchant wallet only after administration approves
     * it, rather than when the browser submits a form.
     */
    private function merchantSummary($ledger, Wallet $wallet): array
    {
        $orders = Order::query();
        $lastSettlement = (clone $ledger)
            ->where(function ($query) {
                $query
                    ->where(function ($legacy) {
                        $legacy->where('type', 'settlement')->where('direction', 1);
                    })
                    ->orWhere(function ($payout) {
                        $payout->where('type', FinanceRequest::MERCHANT_PAYOUT)->where('direction', -1);
                    });
            })
            ->first();

        return [
            // The workflow posts every delivered order to this locked wallet
            // and finance approvals debit the same record.  It is therefore
            // the single amount a merchant can safely request for payout.
            'undisbursed_due' => max(0, (int) $wallet->balance),
            'delivered_orders' => (int) (clone $orders)->where('status', 'delivered')->count(),
            'last_settlement' => $lastSettlement ? [
                'amount' => (int) $lastSettlement->amount,
                'date' => $lastSettlement->date?->toDateString(),
                'ref' => $lastSettlement->ref,
            ] : null,
        ];
    }

    /** Courier figures use delivery records authorised for that courier. */
    private function courierSummary($user, FinanceRequestService $finance): array
    {
        $deliveries = app(CourierOrderAccess::class)->assigned($user);
        $completed = (int) (clone $deliveries)->where('status', 'delivered')->count();
        $returned = (int) (clone $deliveries)->where('status', 'returned')->count();
        $collections = (int) (clone $deliveries)->where('status', 'delivered')->sum('price');

        return [
            'completed_deliveries' => $completed,
            'returned_deliveries' => $returned,
            'collections_total' => $collections,
            'cash_on_hand' => $finance->cashOnHand($user->id),
            'recharge_capacity' => $finance->rechargeCapacity($user->id),
        ];
    }

    public function withdraw(Request $request, FinanceRequestService $finance)
    {
        $user = $request->user();
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1000'],
            'gateway' => ['nullable', 'string', 'max:30'],
        ]);

        // Courier cash is never withdrawn from a mobile browser. It follows
        // the handover and admin verification path below.
        if ($user->role !== 'merchant') {
            return back()->with('error', __('Cash handover requests are available from the courier wallet.'));
        }

        $finance->submit(
            $user,
            FinanceRequest::MERCHANT_PAYOUT,
            (int) $data['amount'],
            null,
            $data['gateway'] ?: null,
        );

        return back()->with('success', __('Settlement request sent to administration.'));
    }

    public function handover(Request $request, FinanceRequestService $finance)
    {
        $user = $request->user();
        abort_unless($user->isCourierRole(), 403);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $finance->submit(
            $user,
            FinanceRequest::CASH_HANDOVER,
            (int) $data['amount'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            $data['note'] ?? null,
        );

        return back()->with('success', __('Cash handover request sent to administration.'));
    }

    public function recharge(Request $request, FinanceRequestService $finance)
    {
        $user = $request->user();
        abort_unless($user->isCourierRole(), 403);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $finance->submit(
            $user,
            FinanceRequest::BUDGET_RECHARGE,
            (int) $data['amount'],
            null,
            $data['note'] ?? null,
        );

        return back()->with('success', __('Budget recharge request sent to administration.'));
    }

    public function budget(Request $request)
    {
        // Kept as a forbidden compatibility endpoint for installed clients
        // from older releases. New releases use the request/approval flow.
        abort(403, __('Unauthorized access'));
    }
}
