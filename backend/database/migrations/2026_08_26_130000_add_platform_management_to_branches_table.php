<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // A shared operations branch is owned by the platform, not by a
            // merchant tenant. It can therefore be assigned to orders for
            // more than one merchant while tenant-owned branches remain
            // private by default.
            $table->boolean('is_platform_managed')->default(false)->index();
        });

        // Preserve branches already created through the administrator screen
        // before this ownership flag existed.
        $platformTenantId = DB::table('tenants')
            ->where('slug', Tenant::PLATFORM_SLUG)
            ->value('id');

        if ($platformTenantId) {
            DB::table('branches')
                ->where('tenant_id', $platformTenantId)
                ->update(['is_platform_managed' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex(['is_platform_managed']);
            $table->dropColumn('is_platform_managed');
        });
    }
};
