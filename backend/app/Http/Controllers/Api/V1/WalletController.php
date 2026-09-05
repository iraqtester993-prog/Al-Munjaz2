<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0, 'budget' => 0, 'budget_balance' => 0]);
        $transactions = Transaction::query()->where('user_id', $user->id)->latest('id')->paginate(min($request->integer('per_page', 20), 100));

        return response()->json(['data' => [
            'balance' => $wallet->balance,
            'budget' => $wallet->budget,
            'budget_balance' => $wallet->budget_balance,
            'transactions' => $transactions->through(fn (Transaction $transaction) => [
                'id' => $transaction->id, 'type' => $transaction->type, 'amount' => $transaction->amount,
                'direction' => $transaction->direction, 'note' => $transaction->note, 'date' => $transaction->date?->toDateString(),
            ]),
        ]]);
    }
}
