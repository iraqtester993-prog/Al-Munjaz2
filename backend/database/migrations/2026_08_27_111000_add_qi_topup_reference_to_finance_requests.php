<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Qi reference belongs to the payment request, not to the immutable
     * ledger entry.  The ledger keeps the platform reference while this
     * field makes the customer-supplied provider transaction auditable.
     */
    public function up(): void
    {
        Schema::table('finance_requests', function (Blueprint $table): void {
            $table->string('external_reference', 120)
                ->nullable()
                ->after('reference')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('finance_requests', function (Blueprint $table): void {
            $table->dropIndex(['external_reference']);
            $table->dropColumn('external_reference');
        });
    }
};
