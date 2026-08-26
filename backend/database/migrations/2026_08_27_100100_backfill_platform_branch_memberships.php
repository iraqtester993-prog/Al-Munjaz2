<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert the old single `users.branch_id` assignment into the new
     * explicit access grant only when both the account and the branch belong
     * to the platform network. Runtime authorisation never relies on the old
     * column, so a tenant-owned merchant branch cannot leak through it.
     */
    public function up(): void
    {
        $platformId = DB::table('tenants')
            ->where('slug', Tenant::PLATFORM_SLUG)
            ->value('id');

        if (! $platformId) {
            return;
        }

        $now = now();

        DB::table('users')
            ->join('branches', 'branches.id', '=', 'users.branch_id')
            ->where('users.tenant_id', $platformId)
            ->whereNull('users.deleted_at')
            ->whereNull('branches.deleted_at')
            ->where('users.status', 'active')
            ->whereIn('users.role', ['owner', 'branch_manager'])
            ->where('branches.tenant_id', $platformId)
            ->where('branches.is_platform_managed', true)
            ->select('users.id as user_id', 'users.role', 'branches.id as branch_id')
            ->orderBy('users.id')
            ->chunkById(250, function ($users) use ($now): void {
                $rows = $users->map(fn (object $user) => [
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->user_id,
                    'access_role' => $user->role === 'owner' ? 'owner' : 'manager',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('branch_memberships')->insertOrIgnore($rows);
            }, 'users.id', 'user_id');
    }

    public function down(): void
    {
        // Memberships are security/audit records. They must not be deleted on
        // rollback because some may have been granted after this migration.
    }
};
