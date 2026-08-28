<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A merchant's public verification badge is an explicit administrative
     * decision.  It must not be inferred from account activation or from a
     * partially reviewed document set.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('merchant_verified_at')->nullable()->after('identity_number');
            $table->foreignId('merchant_verified_by')
                ->nullable()
                ->after('merchant_verified_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merchant_verified_by');
            $table->dropColumn('merchant_verified_at');
        });
    }
};
