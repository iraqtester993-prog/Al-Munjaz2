<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // The courier chooses whether the return keeps the quoted delivery
            // fee.  Keep that decision and its reason on the order itself so
            // merchants can see it long after the notification is read.
            $table->string('return_fee_mode', 12)->nullable()->after('return_fee_applied');
            $table->string('return_reason', 255)->nullable()->after('return_fee_mode');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['return_fee_mode', 'return_reason']);
        });
    }
};
