<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('admin_deduction_per_order')->default(0)->after('vehicle');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Snapshot the courier-specific deduction when the order is claimed.
            // Later edits to the courier account must never rewrite old finance.
            $table->unsignedBigInteger('admin_deduction_applied')->nullable()->after('fee');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('admin_deduction_applied');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('admin_deduction_per_order');
        });
    }
};
