<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch employees use the same precise permission matrix as platform
     * staff, but a profile is owned by one branch and can never be assigned
     * across that boundary.
     */
    public function up(): void
    {
        Schema::table('dashboard_permission_profiles', function (Blueprint $table): void {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->nullOnDelete()
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_permission_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
