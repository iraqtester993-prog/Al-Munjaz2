<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System employees authenticate with a dashboard email and do not take
     * part in the merchant/courier phone workflow. Keeping their phone empty
     * prevents fabricated contact data while preserving the unique constraint
     * for accounts that do have a phone number.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable(false)->change();
        });
    }
};
