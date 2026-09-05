<?php

use App\Models\DashboardPermissionProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expand the previous coarse `update`/`create` grants into the exact
     * actions that they historically controlled. This prevents a deployed
     * employee profile from silently losing access when the granular matrix
     * is introduced.
     */
    public function up(): void
    {
        DB::table('dashboard_permission_profiles')
            ->orderBy('id')
            ->each(function (object $profile): void {
                $permissions = is_string($profile->permissions)
                    ? json_decode($profile->permissions, true)
                    : $profile->permissions;

                $normalized = DashboardPermissionProfile::normalizePermissions(
                    is_array($permissions) ? $permissions : [],
                );

                if ($normalized === $permissions) {
                    return;
                }

                DB::table('dashboard_permission_profiles')
                    ->where('id', $profile->id)
                    ->update([
                        'permissions' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
            });
    }

    /**
     * A lossy reversal would collapse intentional granular choices back into
     * broad powers, so rolling back the schema leaves profile data intact.
     */
    public function down(): void
    {
        // Intentionally no-op.
    }
};
