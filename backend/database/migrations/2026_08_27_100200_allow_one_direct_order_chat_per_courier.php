<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropUnique('chats_order_type_unique');
            // One merchant-to-courier conversation per order participant.
            // This supports distinct pickup and delivery couriers without
            // revealing either courier's history to the other.
            $table->unique(['order_id', 'counterparty_type', 'counterparty_id'], 'chats_order_type_counterparty_unique');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropUnique('chats_order_type_counterparty_unique');
            $table->unique(['order_id', 'counterparty_type'], 'chats_order_type_unique');
        });
    }
};
