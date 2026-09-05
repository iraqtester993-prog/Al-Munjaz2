<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('dashboard_permission_profiles')
            ->orderBy('id')
            ->each(function (object $profile): void {
                $permissions = is_string($profile->permissions)
                    ? json_decode($profile->permissions, true)
                    : $profile->permissions;

                if (! is_array($permissions)) {
                    return;
                }

                $courierActions = $permissions['couriers'] ?? [];
                if (! is_array($courierActions)) {
                    return;
                }

                // Only an actual pre-granular `update` grant may gain this
                // financial capability. A hand-picked modern combination of
                // actions must remain exactly what its administrator chose.
                if (! in_array('update', $courierActions, true)
                    || in_array('update_deduction', $courierActions, true)) {
                    return;
                }

                $permissions['couriers'] = [...$courierActions, 'update_deduction'];

                DB::table('dashboard_permission_profiles')
                    ->where('id', $profile->id)
                    ->update([
                        'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
            });
    }

    /**
     * Do not revoke a separately granted financial capability on rollback.
     */
    public function down(): void
    {
        // Intentionally no-op.
    }
};
