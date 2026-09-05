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
        Schema::table('branches', function (Blueprint $table): void {
            // This is a contact address for the branch itself. The manager's
            // login email remains on users.email and may be the same value.
            $table->string('email', 191)->nullable()->after('phone');

            // A nullable derived key emulates a partial unique index across
            // SQLite and MySQL: only an active platform branch writes its
            // province id here, so inactive/tenant-owned records stay free.
            $table->unsignedBigInteger('active_platform_province_id')
                ->nullable()
                ->after('province_id');
        });

        Schema::table('branch_memberships', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false)->after('access_role');
            // It equals user_id for exactly one primary membership and NULL
            // elsewhere. A unique nullable index enforces that invariant on
            // database engines without partial-index support.
            $table->unsignedBigInteger('primary_user_id')->nullable()->after('is_primary');
            $table->index(['user_id', 'is_primary'], 'branch_memberships_user_primary_index');
            $table->unique('primary_user_id', 'branch_memberships_one_primary_user');
        });

        $this->backfillOperationalProvinceKeys();
        $this->backfillPrimaryMemberships();

        Schema::table('branches', function (Blueprint $table): void {
            $table->unique('active_platform_province_id', 'branches_one_active_platform_province');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropUnique('branches_one_active_platform_province');
            $table->dropColumn(['email', 'active_platform_province_id']);
        });

        Schema::table('branch_memberships', function (Blueprint $table): void {
            $table->dropUnique('branch_memberships_one_primary_user');
            $table->dropIndex('branch_memberships_user_primary_index');
            $table->dropColumn(['is_primary', 'primary_user_id']);
        });
    }

    private function backfillOperationalProvinceKeys(): void
    {
        $platformId = DB::table('tenants')
            ->where('slug', Tenant::PLATFORM_SLUG)
            ->value('id');

        if (! $platformId) {
            return;
        }

        $claimedProvinceIds = [];
        $now = now();

        DB::table('branches')
            ->where('tenant_id', $platformId)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->whereNotNull('province_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->select(['id', 'province_id'])
            ->each(function (object $branch) use (&$claimedProvinceIds, $now): void {
                $provinceId = (int) $branch->province_id;

                if (isset($claimedProvinceIds[$provinceId])) {
                    // Existing dashboard validation already prevents this in
                    // normal operation. If an old import created a duplicate,
                    // keep the oldest live endpoint and safely pause later
                    // duplicates rather than leaving registration ambiguous.
                    DB::table('branches')->where('id', $branch->id)->update([
                        'is_active' => false,
                        'active_platform_province_id' => null,
                        'updated_at' => $now,
                    ]);

                    return;
                }

                $claimedProvinceIds[$provinceId] = true;

                DB::table('branches')->where('id', $branch->id)->update([
                    'active_platform_province_id' => $provinceId,
                    'updated_at' => $now,
                ]);
            });
    }

    private function backfillPrimaryMemberships(): void
    {
        $platformId = DB::table('tenants')
            ->where('slug', Tenant::PLATFORM_SLUG)
            ->value('id');

        if (! $platformId) {
            return;
        }

        $now = now();

        DB::table('users')
            ->whereIn('role', ['owner', 'branch_manager'])
            ->where('tenant_id', $platformId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->select(['id', 'branch_id', 'role'])
            ->each(function (object $user) use ($platformId, $now): void {
                $accessRole = $user->role === 'owner' ? 'owner' : 'manager';
                $memberships = DB::table('branch_memberships as memberships')
                    ->join('branches', 'branches.id', '=', 'memberships.branch_id')
                    ->where('memberships.user_id', $user->id)
                    ->where('memberships.access_role', $accessRole)
                    ->where('branches.tenant_id', $platformId)
                    ->where('branches.is_platform_managed', true)
                    ->whereNull('branches.deleted_at')
                    ->orderByDesc('branches.is_active')
                    ->orderBy('memberships.id')
                    ->get([
                        'memberships.id',
                        'memberships.branch_id',
                        'branches.is_active',
                    ]);

                // Branch memberships have no independent active flag. The
                // query above has already limited them to live platform
                // branches and orders active branches first, so prefer the
                // legacy branch pointer when it is valid, then fall back to
                // that first valid membership.
                $primary = $memberships->first(
                    fn (object $membership): bool => (int) $membership->branch_id === (int) $user->branch_id,
                ) ?? $memberships->first();

                if (! $primary) {
                    return;
                }

                DB::table('branch_memberships')
                    ->where('id', $primary->id)
                    ->update([
                        'is_primary' => true,
                        'primary_user_id' => $user->id,
                        'updated_at' => $now,
                    ]);
            });
    }
};
