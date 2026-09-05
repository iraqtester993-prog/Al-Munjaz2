<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchSettingsResolver;
use App\Services\CourierOrderAssignmentService;
use App\Services\OrderPickupRecoveryService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchOperationalSettingsFlowTest extends TestCase
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

    public function test_two_operational_branches_keep_order_assignment_and_reoffer_settings_isolated(): void
    {
        $baghdad = $this->province('بغداد', 1);
        $basra = $this->province('البصرة', 2);
        $baghdadBranch = $this->branch('BGD-RUNTIME-OPS', $baghdad);
        $basraBranch = $this->branch('BSR-RUNTIME-OPS', $basra);
        $this->setPlatformDefaults();

        $resolver = app(BranchSettingsResolver::class);
        $this->setOperationalOverrides($resolver, $baghdadBranch, [
            'delivery_fee' => 4100,
            'order_expiry_minutes' => 41,
            'admin_deduction_fee' => 610,
            'pickup_eta_minutes' => 13,
        ]);
        $this->setOperationalOverrides($resolver, $basraBranch, [
            'delivery_fee' => 8200,
            'order_expiry_minutes' => 67,
            'admin_deduction_fee' => 970,
            'pickup_eta_minutes' => 19,
        ]);

        $baghdadMerchant = $this->merchant('runtime-baghdad-merchant', $baghdadBranch, $baghdad);
        $basraMerchant = $this->merchant('runtime-basra-merchant', $basraBranch, $basra);
        $baghdadCourier = $this->courier('runtime-baghdad-courier', $baghdadBranch, $baghdad);
        $basraCourier = $this->courier('runtime-basra-courier', $basraBranch, $basra);

        // The PWA controller reads the Baghdad branch. The token API reads
        // Basra. Distinct results prove neither request leaks a cached
        // override into the other operational branch.
        $basraOrder = $this->createApiOrder($basraMerchant, $basra, 'عميل إعدادات البصرة');
        $baghdadOrder = $this->createAppOrder($baghdadMerchant, 'عميل إعدادات بغداد');

        $this->assertSame($baghdadBranch->id, (int) $baghdadOrder->branch_id);
        $this->assertSame($basraBranch->id, (int) $basraOrder->branch_id);
        $this->assertSame(4100, (int) $baghdadOrder->fee);
        $this->assertSame(8200, (int) $basraOrder->fee);
        $this->assertOfferWindow($baghdadOrder, 41);
        $this->assertOfferWindow($basraOrder, 67);

        app(CourierOrderAssignmentService::class)->assign(
            $baghdadOrder,
            $baghdadCourier,
            $baghdadCourier,
            'تعيين فرع بغداد للاختبار.',
        );
        app(CourierOrderAssignmentService::class)->assign(
            $basraOrder,
            $basraCourier,
            $basraCourier,
            'تعيين فرع البصرة للاختبار.',
        );

        $baghdadOrder->refresh();
        $basraOrder->refresh();
        $this->assertSame(610, (int) $baghdadOrder->admin_deduction_applied);
        $this->assertSame(970, (int) $basraOrder->admin_deduction_applied);
        $this->assertPickupWindow($baghdadOrder, 13);
        $this->assertPickupWindow($basraOrder, 19);

        $baghdadOrder->forceFill(['pickup_deadline_at' => now()->subMinute()])->save();
        $basraOrder->forceFill(['pickup_deadline_at' => now()->subMinute()])->save();
        app(OrderPickupRecoveryService::class)->reoffer($baghdadOrder, $baghdadMerchant);
        app(OrderPickupRecoveryService::class)->reoffer($basraOrder, $basraMerchant);

        $baghdadOrder->refresh();
        $basraOrder->refresh();
        $this->assertSame('pending', $baghdadOrder->status);
        $this->assertSame('pending', $basraOrder->status);
        $this->assertOfferWindow($baghdadOrder, 41);
        $this->assertOfferWindow($basraOrder, 67);
    }

    public function test_unbranched_legacy_order_uses_platform_operational_defaults(): void
    {
        $province = $this->province('ديالى', 1);
        $this->setPlatformDefaults();
        $merchant = $this->merchant('runtime-unbranched-merchant', null, $province);
        $courier = $this->courier('runtime-unbranched-courier', null, $province);

        $order = $this->createAppOrder($merchant, 'عميل الإعدادات العامة');

        $this->assertNull($order->branch_id);
        $this->assertSame(3000, (int) $order->fee);
        $this->assertOfferWindow($order, 31);

        app(CourierOrderAssignmentService::class)->assign(
            $order,
            $courier,
            $courier,
            'تعيين بدون فرع للاختبار.',
        );

        $order->refresh();
        $this->assertSame(450, (int) $order->admin_deduction_applied);
        $this->assertPickupWindow($order, 17);

        $order->forceFill(['pickup_deadline_at' => now()->subMinute()])->save();
        app(OrderPickupRecoveryService::class)->reoffer($order, $merchant);

        $this->assertOfferWindow($order->fresh(), 31);
    }

    public function test_dead_or_non_platform_branch_overrides_never_affect_order_lifecycle(): void
    {
        $province = $this->province('واسط', 1);
        $this->setPlatformDefaults();

        $resolver = app(BranchSettingsResolver::class);
        $liveBranch = $this->branch('WST-RUNTIME-SECURE', $province);
        $this->setOperationalOverrides($resolver, $liveBranch, [
            'delivery_fee' => 9100,
            'order_expiry_minutes' => 71,
            'admin_deduction_fee' => 990,
            'pickup_eta_minutes' => 29,
        ]);

        $merchant = $this->merchant('runtime-security-merchant', $liveBranch, $province);
        // An unbranched courier is still authorised by province. This lets us
        // exercise recovery of a historical order after its source branch is
        // disabled, without granting that disabled branch to the courier.
        $courier = $this->courier('runtime-security-courier', null, $province);
        $order = $this->createAppOrder($merchant, 'عميل فرع موقوف');

        $this->assertSame(9100, (int) $order->fee);
        $this->assertOfferWindow($order, 71);

        $liveBranch->update(['is_active' => false]);
        // Historical transfers may retain only origin_branch_id. It remains
        // the fallback reference, but must receive the same live-branch
        // verification before an override is used.
        $order->forceFill(['branch_id' => null])->save();

        app(CourierOrderAssignmentService::class)->assign(
            $order,
            $courier,
            $courier,
            'اختبار عودة الإعدادات بعد تعطيل الفرع.',
        );

        $order->refresh();
        $this->assertSame(450, (int) $order->admin_deduction_applied);
        $this->assertPickupWindow($order, 17);

        $order->forceFill(['pickup_deadline_at' => now()->subMinute()])->save();
        app(OrderPickupRecoveryService::class)->reoffer($order, $merchant);
        $this->assertOfferWindow($order->fresh(), 31);

        $inactiveBranch = $this->branch('WST-INACTIVE-SETTINGS', $province);
        $this->setOperationalOverrides($resolver, $inactiveBranch, ['delivery_fee' => 9200]);
        $inactiveBranch->update(['is_active' => false]);

        $deletedBranch = $this->branch('WST-DELETED-SETTINGS', $province);
        $this->setOperationalOverrides($resolver, $deletedBranch, ['delivery_fee' => 9300]);
        $deletedBranch->delete();

        $foreignTenant = Tenant::create([
            'slug' => 'runtime-foreign-branch-tenant',
            'name' => 'شركة فرع خارجي',
            'kind' => 'merchant',
            'status' => 'active',
        ]);
        $foreignBranch = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $foreignTenant->id,
            'is_platform_managed' => true,
            'code' => 'WST-FOREIGN-SETTINGS',
            'name_ar' => 'فرع خارجي',
            'province_id' => $province->id,
            'is_active' => true,
        ]);
        $this->setOperationalOverrides($resolver, $foreignBranch, ['delivery_fee' => 9400]);

        $privateBranch = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'is_platform_managed' => false,
            'code' => 'WST-PRIVATE-SETTINGS',
            'name_ar' => 'فرع خاص',
            'province_id' => $province->id,
            'is_active' => true,
        ]);
        $this->setOperationalOverrides($resolver, $privateBranch, ['delivery_fee' => 9500]);

        foreach ([$inactiveBranch, $deletedBranch, $foreignBranch, $privateBranch] as $invalidBranch) {
            $this->assertSame(
                3000,
                (int) $resolver->getForOperationalBranch($invalidBranch, 'delivery_fee'),
            );
        }

        // Both order-creation controllers bypass the tenant scope to look up
        // platform branches. Explicitly asserting this prevents a
        // soft-deleted row from silently returning to that lookup.
        $deletedOrderBranch = $this->branch('WST-DELETED-ORDER', $province);
        $this->setOperationalOverrides($resolver, $deletedOrderBranch, ['delivery_fee' => 9600]);
        $deletedMerchant = $this->merchant('runtime-baghdad-merchant', $deletedOrderBranch, $province);
        $deletedOrderBranch->delete();

        $this->actingAs($deletedMerchant)
            ->post('/app/orders', $this->orderPayload('عميل فرع محذوف', '07740000004'))
            ->assertSessionHasErrors('branch');

        $token = $deletedMerchant->createToken('deleted-branch-runtime-test')->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/v1/orders', [
                ...$this->orderPayload('عميل API فرع محذوف', '07740000005'),
                'province_id' => $province->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch');
    }

    private function setPlatformDefaults(): void
    {
        Setting::set('delivery_fee', 3000);
        Setting::set('order_expiry_minutes', 31);
        Setting::set('admin_deduction_fee', 450);
        Setting::set('pickup_eta_minutes', 17);
    }

    /** @param array<string, int> $settings */
    private function setOperationalOverrides(BranchSettingsResolver $resolver, Branch $branch, array $settings): void
    {
        foreach ($settings as $key => $value) {
            $resolver->set($branch, $key, $value);
        }
    }

    private function province(string $name, int $sortOrder): Province
    {
        return Province::create([
            'name_ar' => $name,
            'name_en' => $name,
            'name_ku' => $name,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    private function branch(string $code, Province $province): Branch
    {
        return Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'is_platform_managed' => true,
            'code' => $code,
            'name_ar' => 'فرع '.$province->name_ar,
            'province_id' => $province->id,
            'is_active' => true,
        ]);
    }

    private function merchant(string $slug, ?Branch $branch, Province $province): User
    {
        $tenant = Tenant::create([
            'slug' => $slug,
            'name' => 'شركة '.$slug,
            'kind' => 'merchant',
            'status' => 'active',
        ]);
        $merchant = User::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch?->id,
            'name' => $slug,
            'username' => $slug,
            'email' => $slug.'@example.test',
            'phone' => $slug === 'runtime-baghdad-merchant' ? '07730000001' : ($slug === 'runtime-basra-merchant' ? '07730000002' : '07730000003'),
            'password' => 'StrongPassword123!',
            'role' => 'merchant',
            'status' => 'active',
        ]);
        $merchant->provinces()->attach($province->id, ['is_primary' => true]);

        return $merchant;
    }

    private function courier(string $username, ?Branch $branch, Province $province): User
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'runtime-courier-office'],
            ['name' => 'مكتب المندوبين التجريبي', 'kind' => 'courier', 'status' => 'active'],
        );
        $courier = User::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch?->id,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => $username === 'runtime-baghdad-courier' ? '07830000001' : ($username === 'runtime-basra-courier' ? '07830000002' : '07830000003'),
            'password' => 'StrongPassword123!',
            'role' => 'courier',
            'status' => 'active',
            'vehicle' => 'bike',
            'courier_verified' => true,
            'is_online' => true,
            'admin_deduction_per_order' => 0,
        ]);
        $courier->provinces()->attach($province->id, ['is_primary' => true]);
        $courier->wallet()->create([
            'balance' => 100000,
            'budget' => 100000,
            'budget_balance' => 100000,
        ]);

        return $courier;
    }

    private function createAppOrder(User $merchant, string $customer): Order
    {
        $this->actingAs($merchant)
            ->post('/app/orders', $this->orderPayload($customer, '07740000001'))
            ->assertRedirect();

        return Order::withoutGlobalScopes()
            ->where('merchant_id', $merchant->id)
            ->where('customer_name_ar', $customer)
            ->latest('id')
            ->firstOrFail();
    }

    private function createApiOrder(User $merchant, Province $province, string $customer): Order
    {
        $token = $merchant->createToken('branch-runtime-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/orders', [
                ...$this->orderPayload($customer, '07740000002'),
                'province_id' => $province->id,
            ])
            ->assertCreated();

        return Order::withoutGlobalScopes()
            ->where('merchant_id', $merchant->id)
            ->where('customer_name_ar', $customer)
            ->latest('id')
            ->firstOrFail();
    }

    /** @return array<string, int|string|float> */
    private function orderPayload(string $customer, string $phone): array
    {
        return [
            'customer_name_ar' => $customer,
            'phone' => $phone,
            'address_ar' => 'عنوان اختبار الطلب',
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => 'موقع الاستلام التجريبي',
            'delivery_vehicle' => 'normal',
            'price' => 10000,
        ];
    }

    private function assertOfferWindow(Order $order, int $minutes): void
    {
        $this->assertNotNull($order->offer_opened_at);
        $this->assertNotNull($order->pickup_deadline_at);
        $this->assertEquals($minutes * 60, $order->offer_opened_at->diffInSeconds($order->pickup_deadline_at));
    }

    private function assertPickupWindow(Order $order, int $minutes): void
    {
        $secondsUntilDeadline = now()->diffInSeconds($order->pickup_deadline_at, false);

        $this->assertGreaterThanOrEqual(($minutes * 60) - 1, $secondsUntilDeadline);
        $this->assertLessThanOrEqual(($minutes * 60) + 1, $secondsUntilDeadline);
    }
}
