<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            // `budget` is the declared cash-budget ceiling.  This separate
            // running balance is the only amount that is reserved by open
            // courier jobs, so a completed job never changes the declared
            // budget shown to the courier.
            $table->unsignedBigInteger('budget_balance')->default(0)->after('budget');
        });

        // Existing installations used `budget` as the available balance.
        // Start them at the same amount so installing this release cannot
        // make an otherwise funded courier unable to accept a job.
        DB::table('wallets')->update([
            'budget_balance' => DB::raw('budget'),
        ]);
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn('budget_balance');
        });
    }
};
