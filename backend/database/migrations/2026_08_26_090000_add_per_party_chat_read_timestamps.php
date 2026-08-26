<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A chat has one public account and the operations side.  A single
     * `unread` counter cannot accurately describe both parties, so keep a
     * read cursor for each side and calculate the badge from real messages.
     */
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->timestamp('user_read_at')->nullable()->after('unread');
            $table->timestamp('admin_read_at')->nullable()->after('user_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropColumn(['user_read_at', 'admin_read_at']);
        });
    }
};
