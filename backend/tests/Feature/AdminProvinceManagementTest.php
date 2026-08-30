<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\DashboardPermissionProfile;
use App\Models\Order;
use App\Models\Province;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProvinceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
        $this->seed(PlanSeeder::class);
        $this->seed(ProvinceSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_settings_exposes_shared_governorates_and_their_management_capabilities(): void
    {
        $baghdad = Province::query()->where('name_ar', 'بغداد')->firstOrFail();

        $response = $this->actingAs($this->superAdmin())
            ->get('/dashboard/settings')
            ->assertOk();

        $props = $response->inertiaPage()['props'];
        $baghdadPayload = collect($props['provinces'])->firstWhere('id', $baghdad->id);

        $this->assertTrue($props['canViewProvinces']);
        $this->assertTrue($props['canCreateProvinces']);
        $this->assertTrue($props['canUpdateProvinces']);
        $this->assertSame(18, count($props['provinces']));
        $this->assertTrue((bool) $baghdadPayload['is_active']);
        $this->assertSame(1, $baghdadPayload['branches_count']);
    }

    public function test_settings_update_operator_can_create_edit_and_toggle_a_governorate(): void
    {
        $operator = $this->operator(['settings' => ['view', 'update']]);

        $this->actingAs($operator)
            ->post('/dashboard/settings/provinces', [
                'name_ar' => '  محافظة الاختبار  ',
                'name_en' => 'Test Governorate',
                'name_ku' => 'پارێزگای تاقیکردنەوە',
                'sort_order' => 99,
            ])
            ->assertRedirect();

        $province = Province::platform()->where('name_ar', 'محافظة الاختبار')->firstOrFail();
        $this->assertSame('Test Governorate', $province->name_en);
        $this->assertSame('پارێزگای تاقیکردنەوە', $province->name_ku);
        $this->assertSame(99, $province->sort_order);
        $this->assertTrue($province->is_active);

        $this->actingAs($operator)
            ->put("/dashboard/settings/provinces/{$province->id}", [
                'name_ar' => 'محافظة الاختبار المعدلة',
                'name_en' => '',
                'name_ku' => '',
                'sort_order' => 12,
            ])
            ->assertRedirect();

        $province->refresh();
        $this->assertSame('محافظة الاختبار المعدلة', $province->name_ar);
        $this->assertSame('محافظة الاختبار المعدلة', $province->name_en);
        $this->assertNull($province->name_ku);
        $this->assertSame(12, $province->sort_order);

        $this->actingAs($operator)
            ->patch("/dashboard/settings/provinces/{$province->id}/status", ['is_active' => false])
            ->assertRedirect();

        $this->assertFalse($province->fresh()->is_active);
        $this->assertSame(3, ActivityLog::query()
            ->where('subject_type', 'province')
            ->where('subject_id', $province->id)
            ->count());
    }

    public function test_inactive_governorate_is_hidden_from_the_new_branch_dropdown_without_detaching_its_branch(): void
    {
        $baghdad = Province::query()->where('name_ar', 'بغداد')->firstOrFail();
        $branch = Branch::query()->where('province_id', $baghdad->id)->firstOrFail();
        $baghdad->update(['is_active' => false]);

        $response = $this->actingAs($this->superAdmin())
            ->get('/dashboard/branches')
            ->assertOk();

        $props = $response->inertiaPage()['props'];
        $branchPayload = collect($props['branches'])->firstWhere('id', $branch->id);

        $this->assertFalse(collect($props['provinces'])->contains('id', $baghdad->id));
        $this->assertSame($baghdad->id, $branchPayload['province_id']);
        $this->assertSame($baghdad->id, $branchPayload['province']['id']);
    }

    public function test_settings_viewer_can_read_but_cannot_mutate_governorates_or_tenant_specific_records(): void
    {
        $viewer = $this->operator(['settings' => ['view']]);

        $response = $this->actingAs($viewer)
            ->get('/dashboard/settings')
            ->assertOk();

        $props = $response->inertiaPage()['props'];
        $this->assertTrue($props['canViewProvinces']);
        $this->assertFalse($props['canCreateProvinces']);
        $this->assertFalse($props['canUpdateProvinces']);
        $this->assertNotEmpty($props['provinces']);

        $this->actingAs($viewer)
            ->post('/dashboard/settings/provinces', ['name_ar' => 'محاولة غير مصرح بها'])
            ->assertForbidden();

        $privateProvince = Province::create([
            'tenant_id' => User::query()->where('role', 'merchant')->value('tenant_id'),
            'name_ar' => 'محافظة تاجر خاصة',
            'name_en' => 'Tenant Province',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $manager = $this->operator(['settings' => ['view', 'update']]);
        $this->actingAs($manager)
            ->patch("/dashboard/settings/provinces/{$privateProvince->id}/status", ['is_active' => false])
            ->assertNotFound();

        $this->assertTrue($privateProvince->fresh()->is_active);
    }

    public function test_inactive_governorates_are_excluded_from_public_registration_and_order_creation(): void
    {
        $baghdad = Province::query()->where('name_ar', 'بغداد')->firstOrFail();
        $historicalOrder = Order::query()->where('province_id', $baghdad->id)->firstOrFail();
        $historicalStatus = $historicalOrder->status;

        $baghdad->update(['is_active' => false]);

        $this->getJson('/api/v1/provinces')
            ->assertOk()
            ->assertJsonMissing(['id' => $baghdad->id]);

        $this->get('/register/merchant')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('registrationAvailable', false)
                ->has('provinces', 0));

        $merchant = User::query()->where('role', 'merchant')->firstOrFail();
        Sanctum::actingAs($merchant);

        $this->postJson('/api/v1/orders', [
            'customer_name_ar' => 'عميل محافظة موقفة',
            'phone' => '07900001234',
            'address_ar' => 'بغداد',
            'pickup_latitude' => 33.3152,
            'pickup_longitude' => 44.3661,
            'pickup_location_label' => 'نقطة الاستلام',
            'delivery_vehicle' => 'normal',
            'province_id' => $baghdad->id,
            'price' => 5000,
        ])->assertUnprocessable();

        // Deactivation controls new operational access only. Existing order
        // references remain intact for their lifecycle and audit history.
        $this->assertSame($baghdad->id, (int) $historicalOrder->fresh()->province_id);
        $this->assertSame($historicalStatus, $historicalOrder->fresh()->status);
    }

    private function superAdmin(): User
    {
        return User::query()->where('username', 'admin')->firstOrFail();
    }

    /** @param array<string, array<int, string>> $permissions */
    private function operator(array $permissions): User
    {
        static $sequence = 0;
        $sequence++;

        $profile = DashboardPermissionProfile::create([
            'name' => "مشغل المحافظات {$sequence}",
            'permissions' => $permissions,
        ]);

        return User::create([
            'tenant_id' => Tenant::platform()->id,
            'name' => "مشغل محافظات {$sequence}",
            'username' => "province-operator-{$sequence}",
            'email' => "province-operator-{$sequence}@example.test",
            'phone' => '0797000'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'password' => 'Password123!',
            'role' => 'admin',
            'status' => 'active',
            'permission_profile_id' => $profile->id,
            'is_super_admin' => false,
        ]);
    }
}
