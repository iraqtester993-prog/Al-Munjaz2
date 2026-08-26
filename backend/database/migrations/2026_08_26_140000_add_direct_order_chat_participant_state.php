<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A direct order conversation has two operational participants (the
     * merchant and its assigned courier).  Support chats continue to use the
     * existing public-account/admin read cursors, while direct chats receive
     * a distinct cursor for their counterparty.
     */
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->timestamp('counterparty_read_at')->nullable()->after('user_read_at');
            $table->unique(['order_id', 'counterparty_type'], 'chats_order_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropUnique('chats_order_type_unique');
            $table->dropColumn('counterparty_read_at');
        });
    }
};
