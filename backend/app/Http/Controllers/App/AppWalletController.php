<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AppWalletController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $wallet = $user->wallet;

        if (! $wallet) {
            $wallet = Wallet::create(['user_id' => $user->id, 'balance' => 0, 'budget' => 0]);
        }

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->latest('date')
            ->limit(50)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => $tx->amount,
                'direction' => $tx->direction,
                'ref' => $tx->ref,
                'date' => $tx->date->toDateString(),
                'note' => $tx->note,
            ]);

        return Inertia::render('Mobile/Wallet', [
            'isCourier' => $user->role === 'courier',
            'balance' => $wallet->balance,
            'budget' => $wallet->budget,
            'transactions' => $transactions,
        ]);
    }

    public function withdraw(Request $request)
    {
        $user = $request->user();
        $wallet = $user->wallet;

        $request->validate([
            'amount' => ['required', 'integer', 'min:1000'],
            'gateway' => ['nullable', 'string', 'max:30'],
        ]);

        $amount = (int) $request->input('amount');

        if (! $wallet || $wallet->balance < $amount) {
            return back()->with('error', __('wallet.insufficient'));
        }

        $wallet->decrement('balance', $amount);

        Transaction::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => 'withdrawal',
            'amount' => $amount,
            'direction' => -1,
            'ref' => 'WD-'.mt_rand(1000, 9999),
            'date' => today(),
            'note' => $request->input('gateway') ?: null,
        ]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'action' => 'wallet.withdraw',
            'subject_type' => 'wallet',
            'subject_id' => $wallet->id,
            'data' => ['amount' => $amount],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('wallet.withdrawn', ['amount' => $amount]));
    }

    public function budget(Request $request)
    {
        $user = $request->user();

        abort_unless($user->role === 'courier', 403);

        $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'mode' => ['required', Rule::in(['add', 'set'])],
        ]);

        $wallet = $user->wallet ?? Wallet::create(['user_id' => $user->id, 'balance' => 0, 'budget' => 0]);
        $amount = (int) $request->input('amount');

        if ($request->input('mode') === 'set') {
            $delta = $amount - $wallet->budget;
            $wallet->update(['budget' => $amount]);
        } else {
            $delta = $amount;
            $wallet->increment('budget', $amount);
        }

        if ($delta !== 0) {
            Transaction::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'type' => $delta > 0 ? 'cash_added' : 'budget_deduct',
                'amount' => abs($delta),
                'direction' => $delta > 0 ? 1 : -1,
                'ref' => 'BUD-'.mt_rand(1000, 9999),
                'date' => today(),
                'note' => 'الميزانية',
            ]);
        }

        return back()->with('success', __('wallet.budget_updated'));
    }
}
