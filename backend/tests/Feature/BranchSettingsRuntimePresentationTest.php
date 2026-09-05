<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchSettingsResolver;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchSettingsRuntimePresentationTest extends TestCase
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

    public function test_active_operational_branch_overrides_mobile_branding_developer_content_and_dashboard_defaults(): void
    {
        $branch = $this->branch('BGD-RUNTIME', 'بغداد', 1);
        $courier = $this->user('courier', 'runtime-branch-courier', $branch->id, [
            'courier_verified' => true,
        ]);
        $this->setPlatformDefaults();

        $resolver = app(BranchSettingsResolver::class);
        $resolver->set($branch, Setting::BRANDING_KEY, [
            'name' => 'هوية فرع بغداد',
            'tagline' => 'توصيل بغداد المحلي',
        ]);
        $resolver->set($branch, Setting::PUBLIC_CONTENT_KEY, [
            'about_app' => ['ar' => 'نبذة تطبيق فرع بغداد'],
            'developer_name' => ['ar' => 'فريق بغداد'],
            'developer_description' => ['ar' => 'خدمة فرع بغداد'],
            // A malformed/direct database value must not turn legal text into
            // a branch setting. The resolver and the legal routes both keep
            // these platform-wide.
            'privacy_policy' => ['ar' => 'خصوصية محلية ممنوعة'],
            'terms_of_use' => ['ar' => 'شروط محلية ممنوعة'],
        ]);
        $resolver->set($branch, 'order_expiry_minutes', 55);
        $resolver->set($branch, 'admin_deduction_fee', 2400);

        $props = $this->appProps($courier);

        $this->assertSame('هوية فرع بغداد', $props['branding']['name']);
        $this->assertSame('توصيل بغداد المحلي', $props['branding']['tagline']);
        $this->assertSame('نبذة تطبيق فرع بغداد', $props['developer']['about_app']['ar']);
        $this->assertSame('فريق بغداد', $props['developer']['developer_name']['ar']);
        $this->assertSame('خدمة فرع بغداد', $props['developer']['developer_description']['ar']);
        $this->assertArrayNotHasKey('privacy_policy', $props['developer']);
        $this->assertArrayNotHasKey('terms_of_use', $props['developer']);
        $this->assertSame(55, $props['orderExpiryMinutes']);
        $this->assertSame(2400, $props['stats']['adminDeduction']);

        $privacyProps = $this->actingAs($courier)
            ->get(route('legal.privacy'))
            ->assertOk()
            ->inertiaPage()['props'];
        $termsProps = $this->actingAs($courier)
            ->get(route('legal.terms'))
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame('خصوصية المنصة', $privacyProps['legalContent']['privacy_policy']['ar']);
        $this->assertSame('شروط المنصة', $termsProps['legalContent']['terms_of_use']['ar']);

        $this->actingAs($courier)
            ->getJson('/app')
            ->assertOk()
            ->assertJsonPath('orderExpiryMinutes', 55);
    }

    public function test_unassigned_inactive_and_deleted_branches_always_receive_platform_defaults(): void
    {
        $this->setPlatformDefaults();
        $resolver = app(BranchSettingsResolver::class);

        $unassignedCourier = $this->user('courier', 'runtime-global-courier', null, [
            'courier_verified' => true,
        ]);
        $unassignedProps = $this->appProps($unassignedCourier);
        $this->assertPlatformRuntimeDefaults($unassignedProps);

        $inactiveBranch = $this->branch('BSR-INACTIVE', 'البصرة', 1, false);
        $resolver->set($inactiveBranch, Setting::BRANDING_KEY, ['name' => 'لا يجب عرضه']);
        $resolver->set($inactiveBranch, 'order_expiry_minutes', 70);
        $resolver->set($inactiveBranch, 'admin_deduction_fee', 3100);
        $inactiveCourier = $this->user('courier', 'runtime-inactive-courier', $inactiveBranch->id, [
            'courier_verified' => true,
        ]);
        $this->assertPlatformRuntimeDefaults($this->appProps($inactiveCourier));

        $deletedBranch = $this->branch('WAS-DELETED', 'واسط', 2);
        $resolver->set($deletedBranch, Setting::BRANDING_KEY, ['name' => 'لا يجب عرضه بعد الحذف']);
        $resolver->set($deletedBranch, 'order_expiry_minutes', 75);
        $resolver->set($deletedBranch, 'admin_deduction_fee', 3300);
        $deletedBranch->delete();
        $deletedCourier = $this->user('courier', 'runtime-deleted-courier', $deletedBranch->id, [
            'courier_verified' => true,
        ]);
        $this->assertPlatformRuntimeDefaults($this->appProps($deletedCourier));
    }

    private function setPlatformDefaults(): void
    {
        Setting::set(Setting::BRANDING_KEY, [
            'name' => 'هوية المنصة',
            'tagline' => 'توصيل المنصة',
        ]);
        Setting::set(Setting::PUBLIC_CONTENT_KEY, [
            'about_app' => ['ar' => 'نبذة المنصة'],
            'developer_name' => ['ar' => 'فريق المنصة'],
            'developer_description' => ['ar' => 'خدمة المنصة'],
            'privacy_policy' => ['ar' => 'خصوصية المنصة'],
            'terms_of_use' => ['ar' => 'شروط المنصة'],
        ]);
        Setting::set('order_expiry_minutes', 35);
        Setting::set('admin_deduction_fee', 1500);
    }

    /** @param array<string, mixed> $props */
    private function assertPlatformRuntimeDefaults(array $props): void
    {
        $this->assertSame('هوية المنصة', $props['branding']['name']);
        $this->assertSame('توصيل المنصة', $props['branding']['tagline']);
        $this->assertSame('نبذة المنصة', $props['developer']['about_app']['ar']);
        $this->assertSame('فريق المنصة', $props['developer']['developer_name']['ar']);
        $this->assertSame('خدمة المنصة', $props['developer']['developer_description']['ar']);
        $this->assertArrayNotHasKey('privacy_policy', $props['developer']);
        $this->assertArrayNotHasKey('terms_of_use', $props['developer']);
        $this->assertSame(35, $props['orderExpiryMinutes']);
        $this->assertSame(1500, $props['stats']['adminDeduction']);
    }

    /** @return array<string, mixed> */
    private function appProps(User $user): array
    {
        return $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->inertiaPage()['props'];
    }

    private function branch(string $code, string $provinceName, int $sortOrder, bool $isActive = true): Branch
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
            'is_active' => $isActive,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function user(string $role, string $username, ?int $branchId, array $overrides = []): User
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
