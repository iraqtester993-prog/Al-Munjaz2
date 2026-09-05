<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // A pending order may be re-published or re-offered.  Its original
            // created_at must not be used to measure the new acceptance window.
            $table->timestamp('offer_opened_at')->nullable()->after('pickup_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('offer_opened_at');
        });
    }
};
