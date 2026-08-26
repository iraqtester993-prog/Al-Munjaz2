<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Points are deliberately kept outside wallets: money and loyalty
        // balances have different financial and audit rules.
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
        });

        // This is an append-only ledger.  It has no updated_at column on
        // purpose: the account balance is a fast read model and these rows
        // remain the source of truth for every earned or spent point.
        Schema::create('loyalty_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('points');
            $table->unsignedBigInteger('balance_after');
            $table->string('type', 60)->index();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['loyalty_account_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            // A source may be retried by a browser, queue, or status update.
            // The key guarantees a single award for a delivered order while
            // still allowing unreferenced manual adjustments (NULL source).
            $table->unique(['type', 'source_type', 'source_id'], 'loyalty_entries_source_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_entries');
        Schema::dropIfExists('loyalty_accounts');
    }
};
