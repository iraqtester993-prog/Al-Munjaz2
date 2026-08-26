<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Money changes must be auditable before they reach a wallet.  A
         * request is deliberately separate from `transactions`: a courier
         * can ask to hand over cash or recharge a budget, but only an
         * administrator can approve it and create the immutable ledger row.
         */
        Schema::create('finance_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('type', 30)->index();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('approved_amount')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('reference', 60)->unique();
            $table->text('note')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('processed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table) {
            // One request can post only one ledger entry.  This protects an
            // administrator double-click/retry from crediting a balance twice.
            $table->unsignedBigInteger('finance_request_id')->nullable()->unique()->after('id');
            $table->foreign('finance_request_id')
                ->references('id')
                ->on('finance_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['finance_request_id']);
            $table->dropUnique(['finance_request_id']);
            $table->dropColumn('finance_request_id');
        });

        Schema::dropIfExists('finance_requests');
    }
};
