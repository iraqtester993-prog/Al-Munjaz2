<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\DashboardPermissionProfile;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\BranchSettingsResolver;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchSettingsScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_branch_manager_reads_platform_fallbacks_but_writes_only_its_own_override(): void
    {
        $localBranch = $this->branch('BGD-SETTINGS', 'بغداد', 1);
        $foreignBranch = $this->branch('BSR-SETTINGS', 'البصرة', 2);
        $manager = $this->branchManager($localBranch, 'settings-local-manager');

        Setting::set('delivery_fee', 4300);
        Setting::set('support_phone', '07700000000');
        Setting::set(Setting::BRANDING_KEY, [
            'name' => 'هوية المنصة',
            'tagline' => 'وصف المنصة',
        ]);

        $props = $this->actingAs($manager)
            ->get('/dashboard/settings')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertSame('branch', $props['settingsScope']['type']);
        $this->assertSame($localBranch->id, $props['settingsScope']['branch_id']);
        $this->assertSame(4300, $props['settings']['delivery_fee']);
        $this->assertSame('07700000000', $props['settings']['support_phone']);
        $this->assertSame('هوية المنصة', $props['branding']['name']);
        $this->assertFalse($props['canViewProvinces']);
        $this->assertFalse($props['canSelectSettingsBranch']);
        $this->assertSame([], $props['settingsBranches']);

        $this->actingAs($manager)
            ->get('/dashboard/settings?branch_id='.$foreignBranch->id)
            ->assertForbidden();

        // A browser-supplied foreign branch id is rejected rather than
        // changing the manager's server-owned membership boundary.
        $this->actingAs($manager)
            ->post('/dashboard/settings/financial-defaults', [
                'delivery_fee' => 7300,
                'branch_id' => $foreignBranch->id,
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->post('/dashboard/settings/financial-defaults', [
                'delivery_fee' => 7300,
                'branch_id' => $localBranch->id,
            ])
            ->assertRedirect();

        $resolver = app(BranchSettingsResolver::class);
        $this->assertSame(4300, (int) Setting::get('delivery_fee'));
        $this->assertSame(7300, (int) $resolver->get($localBranch, 'delivery_fee'));
        $this->assertSame(4300, (int) $resolver->get($foreignBranch, 'delivery_fee'));
        $this->assertDatabaseHas('branch_settings', [
            'branch_id' => $localBranch->id,
            'key' => 'delivery_fee',
        ]);
        $this->assertDatabaseMissing('branch_settings', [
            'branch_id' => $foreignBranch->id,
            'key' => 'delivery_fee',
        ]);

        $this->actingAs($manager)
            ->post('/dashboard/settings/financial-defaults', ['delivery_fee' => 7600])
            ->assertRedirect();

        $this->assertSame(1, BranchSetting::query()
            ->where('branch_id', $localBranch->id)
            ->where('key', 'delivery_fee')
            ->count());
        $this->assertSame(7600, (int) $resolver->get($localBranch, 'delivery_fee'));

        $this->actingAs($manager)
            ->post('/dashboard/settings/branding', [
                'brand_name' => 'هوية فرع بغداد',
                'brand_tagline' => 'وصف فرع بغداد',
                'branch_id' => $localBranch->id,
            ])
            ->assertRedirect();

        $this->assertSame('هوية المنصة', Setting::branding()['name']);
        $this->assertSame('هوية فرع بغداد', $resolver->branding($localBranch)['name']);
        $this->assertSame('وصف فرع بغداد', $resolver->branding($localBranch)['tagline']);
        $this->assertSame('هوية المنصة', $resolver->branding($foreignBranch)['name']);
    }

    public function test_branch_settings_hide_and_reject_platform_legal_documents(): void
    {
        $branch = $this->branch('WAS-SETTINGS', 'واسط', 1);
        $manager = $this->branchManager($branch, 'settings-content-manager');

        Setting::set(Setting::PUBLIC_CONTENT_KEY, [
            'about_app' => ['ar' => 'نبذة المنصة', 'en' => 'Platform about', 'ku' => 'دەربارەی پلاتفۆرم'],
            'developer_name' => ['ar' => 'الشركة', 'en' => 'Company', 'ku' => 'کۆمپانیا'],
            'developer_description' => ['ar' => 'وصف الشركة', 'en' => 'Company description', 'ku' => 'وەسفی کۆمپانیا'],
            'privacy_policy' => ['ar' => 'خصوصية المنصة', 'en' => 'Platform privacy', 'ku' => 'تایبەتمەندی پلاتفۆرم'],
            'terms_of_use' => ['ar' => 'شروط المنصة', 'en' => 'Platform terms', 'ku' => 'مەرجەکانی پلاتفۆرم'],
        ]);

        $props = $this->actingAs($manager)
            ->get('/dashboard/settings')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertArrayNotHasKey('privacy_policy', $props['settings']['public_content']);
        $this->assertArrayNotHasKey('terms_of_use', $props['settings']['public_content']);

        $this->actingAs($manager)
            ->post('/dashboard/settings/public-content', [
                'public_content' => [
                    'about_app' => ['ar' => 'نبذة فرع واسط'],
                    'privacy_policy' => ['ar' => 'محاولة تغيير الخصوصية'],
                ],
            ])
            ->assertSessionHasErrors('public_content.privacy_policy');

        $this->actingAs($manager)
            ->post('/dashboard/settings/public-content', [
                'public_content' => [
                    'about_app' => ['ar' => 'نبذة فرع واسط'],
                    'developer_name' => ['ar' => 'فريق واسط'],
                ],
            ])
            ->assertRedirect();

        $content = app(BranchSettingsResolver::class)->publicContent($branch);
        $this->assertSame('نبذة فرع واسط', $content['about_app']['ar']);
        $this->assertSame('فريق واسط', $content['developer_name']['ar']);
        $this->assertSame('خصوصية المنصة', $content['privacy_policy']['ar']);
        $this->assertSame('شروط المنصة', $content['terms_of_use']['ar']);
    }

    public function test_platform_legal_documents_are_editable_only_by_the_super_administrator(): void
    {
        Setting::set(Setting::PUBLIC_CONTENT_KEY, [
            'about_app' => ['ar' => 'نبذة قديمة'],
            'privacy_policy' => ['ar' => 'خصوصية السوبر'],
            'terms_of_use' => ['ar' => 'شروط السوبر'],
        ]);

        $profile = DashboardPermissionProfile::create([
            'name' => 'محرر المحتوى العام',
            'permissions' => ['settings' => ['view', 'update_public_content']],
        ]);
        $operator = $this->user('admin', 'delegated-content-editor', null, [
            'permission_profile_id' => $profile->id,
        ]);

        $props = $this->actingAs($operator)
            ->get('/dashboard/settings')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertFalse($props['canManageLegalContent']);

        $this->actingAs($operator)
            ->post('/dashboard/settings/public-content', [
                'public_content' => [
                    'about_app' => ['ar' => 'نبذة يحررها الموظف'],
                    'privacy_policy' => ['ar' => 'محاولة تغيير الخصوصية'],
                    'terms_of_use' => ['ar' => 'محاولة تغيير الشروط'],
                ],
            ])
            ->assertRedirect();

        $content = Setting::publicContent();
        $this->assertSame('نبذة يحررها الموظف', $content['about_app']['ar']);
        $this->assertSame('خصوصية السوبر', $content['privacy_policy']['ar']);
        $this->assertSame('شروط السوبر', $content['terms_of_use']['ar']);

        $superAdmin = $this->user('admin', 'legal-super-admin');
        $superAdmin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($superAdmin)
            ->post('/dashboard/settings/public-content', [
                'public_content' => [
                    'about_app' => ['ar' => 'نبذة السوبر'],
                    'privacy_policy' => ['ar' => 'خصوصية محدثة'],
                    'terms_of_use' => ['ar' => 'شروط محدثة'],
                ],
            ])
            ->assertRedirect();

        $content = Setting::publicContent();
        $this->assertSame('خصوصية محدثة', $content['privacy_policy']['ar']);
        $this->assertSame('شروط محدثة', $content['terms_of_use']['ar']);
    }

    public function test_super_administrator_can_select_a_valid_branch_scope_but_a_limited_admin_cannot(): void
    {
        $firstBranch = $this->branch('KRB-SETTINGS', 'كربلاء', 1);
        $secondBranch = $this->branch('MYS-SETTINGS', 'ميسان', 2);
        $superAdmin = $this->user('admin', 'settings-super-admin');
        $superAdmin->forceFill(['is_super_admin' => true])->save();
        $limitedProfile = DashboardPermissionProfile::create([
            'name' => 'مشاهدة إعدادات المنصة',
            'permissions' => ['settings' => ['view']],
        ]);
        $limitedAdmin = $this->user('admin', 'settings-limited-admin', null, [
            'permission_profile_id' => $limitedProfile->id,
        ]);
        Setting::set('delivery_fee', 3000);

        $props = $this->actingAs($superAdmin)
            ->get('/dashboard/settings?branch_id='.$secondBranch->id)
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertSame('branch', $props['settingsScope']['type']);
        $this->assertSame($secondBranch->id, $props['settingsScope']['branch_id']);
        $this->assertTrue($props['canSelectSettingsBranch']);
        $this->assertEqualsCanonicalizing([$firstBranch->id, $secondBranch->id], collect($props['settingsBranches'])->pluck('id')->all());
        $this->assertArrayNotHasKey('privacy_policy', $props['settings']['public_content']);

        $this->actingAs($superAdmin)
            ->post('/dashboard/settings/financial-defaults', [
                'branch_id' => $secondBranch->id,
                'delivery_fee' => 8100,
            ])
            ->assertRedirect();

        $resolver = app(BranchSettingsResolver::class);
        $this->assertSame(3000, (int) Setting::get('delivery_fee'));
        $this->assertSame(3000, (int) $resolver->get($firstBranch, 'delivery_fee'));
        $this->assertSame(8100, (int) $resolver->get($secondBranch, 'delivery_fee'));

        $this->actingAs($limitedAdmin)
            ->get('/dashboard/settings?branch_id='.$firstBranch->id)
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get('/dashboard/settings?branch_id=999999')
            ->assertNotFound();
    }

    public function test_branch_permission_profile_needs_the_exact_local_settings_action(): void
    {
        $branch = $this->branch('DHI-SETTINGS', 'ذي قار', 1);
        $profile = DashboardPermissionProfile::create([
            'branch_id' => $branch->id,
            'name' => 'قراءة إعدادات الفرع فقط',
            'permissions' => ['settings' => ['view']],
        ]);
        $operator = $this->branchManager($branch, 'settings-restricted-operator', [
            'permission_profile_id' => $profile->id,
        ]);

        $props = $this->actingAs($operator)
            ->get('/dashboard/settings')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertTrue($props['canViewSettings']);
        $this->assertFalse($props['canUpdateFinancialDefaults']);

        $this->actingAs($operator)
            ->post('/dashboard/settings/financial-defaults', ['delivery_fee' => 5000])
            ->assertForbidden();

        $profile->update(['permissions' => ['settings' => ['view', 'update_financial_defaults']]]);
        $operator = $operator->fresh();

        $this->actingAs($operator)
            ->post('/dashboard/settings/financial-defaults', ['delivery_fee' => 5000])
            ->assertRedirect();

        $this->assertSame(5000, (int) app(BranchSettingsResolver::class)->get($branch, 'delivery_fee'));
    }

    private function branch(string $code, string $provinceName, int $sortOrder): Branch
    {
        $province = Province::create([
            'name_ar' => $provinceName,
            'name_en' => $provinceName,
            'name_ku' => $provinceName,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);

        return Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'is_platform_managed' => true,
            'code' => $code,
            'name_ar' => 'فرع '.$provinceName,
            'province_id' => $province->id,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function branchManager(Branch $branch, string $username, array $overrides = []): User
    {
        $manager = $this->user('branch_manager', $username, $branch->id, $overrides);
        app(BranchDashboardContext::class)->assignPrimaryMembership($manager, $branch);

        return $manager->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function user(string $role, string $username, ?int $branchId = null, array $overrides = []): User
    {
        return User::create([
            'tenant_id' => Tenant::platform()->id,
            'branch_id' => $branchId,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => '079'.str_pad((string) (10000000 + User::withoutGlobalScopes()->count()), 8, '0', STR_PAD_LEFT),
            'password' => 'StrongPassword123!',
            'role' => $role,
            'status' => 'active',
            ...$overrides,
        ]);
    }
}
