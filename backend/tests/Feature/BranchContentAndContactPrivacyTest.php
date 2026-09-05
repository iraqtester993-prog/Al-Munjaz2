<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchContentAndContactPrivacyTest extends TestCase
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

    public function test_branch_manager_cannot_open_the_retired_content_console(): void
    {
        $platform = Tenant::platform();
        $manager = User::create([
            'tenant_id' => $platform->id,
            'name' => 'مدير محتوى',
            'username' => 'content-manager',
            'phone' => '07987110001',
            'password' => 'StrongPassword123!',
            'role' => 'branch_manager',
            'status' => 'active',
            'dashboard_permissions' => ['content'],
        ]);

        $this->actingAs($manager)
            ->get('/dashboard/branch/content')
            ->assertNotFound();
    }

    public function test_api_and_pwa_payloads_show_customer_phone_for_every_visible_order_status(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $provinceId = $merchant->provinces()->value('provinces.id');
        $order = Order::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-CONTACT-PRIVATE',
            'source' => 'merchant',
            'customer_name_ar' => 'زبون خاص',
            'phone' => '07870009999',
            'phone2' => '07870008888',
            'address_ar' => 'بغداد — الاختبار',
            'delivery_vehicle' => 'normal',
            'price' => 18000,
            'fee' => 1800,
            'status' => 'approved',
            'workflow_stage' => 'awaiting_pickup',
            'courier_id' => $courier->id,
            'province_id' => $provinceId,
            'date' => today(),
        ]);

        Sanctum::actingAs($courier);
        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.phone', '07870009999')
            ->assertJsonPath('data.phone2', '07870008888')
            ->assertJsonPath('data.phone_revealed', true);

        $pwaBeforePickup = $this->actingAs($courier)->get('/app/orders?list=1')->assertOk();
        $pwaOrderBeforePickup = collect($pwaBeforePickup->inertiaProps('orders'))->firstWhere('id', $order->id);
        $this->assertSame('07870009999', data_get($pwaOrderBeforePickup, 'phone'));
        $this->assertSame('07870008888', data_get($pwaOrderBeforePickup, 'phone2'));
        $this->assertTrue((bool) data_get($pwaOrderBeforePickup, 'phone_revealed'));

        $order->update(['picked_at' => now(), 'status' => 'courier']);

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.phone', '07870009999')
            ->assertJsonPath('data.phone2', '07870008888')
            ->assertJsonPath('data.phone_revealed', true);

        $pwaAfterPickup = $this->actingAs($courier)->get('/app/orders?list=1')->assertOk();
        $pwaOrderAfterPickup = collect($pwaAfterPickup->inertiaProps('orders'))->firstWhere('id', $order->id);
        $this->assertSame('07870009999', data_get($pwaOrderAfterPickup, 'phone'));
        $this->assertSame('07870008888', data_get($pwaOrderAfterPickup, 'phone2'));
        $this->assertTrue((bool) data_get($pwaOrderAfterPickup, 'phone_revealed'));
    }
}
