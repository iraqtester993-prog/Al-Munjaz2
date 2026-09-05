<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch overrides deliberately live outside the platform `settings`
     * table.  That table has a globally unique key and remains the fallback
     * source for every branch that has not chosen a local override.
     */
    public function up(): void
    {
        Schema::create('branch_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'key'], 'branch_settings_branch_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_settings');
    }
};
