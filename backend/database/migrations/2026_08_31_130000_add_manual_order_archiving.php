<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Archiving is a courier's explicit acknowledgement that a
            // completed delivery no longer belongs in an active status queue.
            // It never deletes or changes the order's operational status.
            $table->timestamp('archived_at')->nullable()->after('return_fee_charged_at');
            $table->index(['archived_at', 'status', 'id'], 'orders_archive_status_id_index');
        });

        // Preserve historic completed work when this feature is introduced:
        // terminal delivery/return rows already shown in the old archive are
        // treated as archived rather than disappearing from it on upgrade.
        DB::table('orders')
            ->whereIn('status', ['delivered', 'returned'])
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_archive_status_id_index');
            $table->dropColumn('archived_at');
        });
    }
};
