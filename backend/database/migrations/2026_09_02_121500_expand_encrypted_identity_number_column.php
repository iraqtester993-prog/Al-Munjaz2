<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The identity number is stored with Laravel's encrypted cast. Even a
     * short value becomes a few hundred characters after encryption, which
     * cannot fit in the original VARCHAR(100) column on MySQL.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('identity_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('identity_number', 100)->nullable()->change();
        });
    }
};
