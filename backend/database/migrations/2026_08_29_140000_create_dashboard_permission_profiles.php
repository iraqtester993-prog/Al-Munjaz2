<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_permission_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->json('permissions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            // Existing administrators keep their historic full access only
            // through this explicit, auditable flag. New dashboard operators
            // default to false and require an assigned named profile.
            $table->boolean('is_super_admin')->default(false)->index();
            $table->foreignId('permission_profile_id')
                ->nullable()
                ->constrained('dashboard_permission_profiles')
                ->nullOnDelete();
        });

        Schema::table('dashboard_invitations', function (Blueprint $table): void {
            // An invitation may pre-select a profile. Keeping it nullable
            // preserves old invitation links; a newly accepted invitation
            // with no profile is intentionally denied dashboard access until
            // a super administrator assigns one.
            $table->foreignId('permission_profile_id')
                ->nullable()
                ->constrained('dashboard_permission_profiles')
                ->nullOnDelete();
        });

        // Do not silently reduce access for existing production operators at
        // deploy time. New accounts are still opt-in because the column
        // default is false and the invitation flow never sets it to true.
        DB::table('users')->where('role', 'admin')->update(['is_super_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('dashboard_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('permission_profile_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('permission_profile_id');
            $table->dropIndex(['is_super_admin']);
            $table->dropColumn('is_super_admin');
        });

        Schema::dropIfExists('dashboard_permission_profiles');
    }
};
