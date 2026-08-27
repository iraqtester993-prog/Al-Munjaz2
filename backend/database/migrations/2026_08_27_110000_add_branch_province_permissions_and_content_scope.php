<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational branches are the source of truth for the provinces that
     * can be selected in the mobile application.  A nullable foreign key
     * makes this safe for existing installations: an operator can assign the
     * province from the dashboard before exposing that branch for sign-up.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->foreignId('province_id')
                ->nullable()
                ->after('city')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            // Branch-manager permissions are intentionally a JSON allow-list.
            // Platform administrators retain full access in policy checks and
            // do not need an editable duplicate of their authority.
            $table->json('dashboard_permissions')->nullable()->after('branch_id');
        });

        Schema::table('mobile_slides', function (Blueprint $table): void {
            // Null means platform-wide; a branch id means content only for
            // users operating through that branch.
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_slides', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('dashboard_permissions');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('province_id');
        });
    }
};
