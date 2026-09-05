<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Courier operational approval is separate from account activation and
     * from the merchant's public verification badge. Existing accounts keep
     * their ability to finish active work; only newly registered couriers are
     * explicitly created with courier_verified = false.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('courier_verified')->default(true)->after('merchant_verified_by');
            $table->timestamp('courier_verified_at')->nullable()->after('courier_verified');
            $table->foreignId('courier_verified_by')
                ->nullable()
                ->after('courier_verified_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('courier_verified_by');
            $table->dropColumn(['courier_verified_at', 'courier_verified']);
        });
    }
};
