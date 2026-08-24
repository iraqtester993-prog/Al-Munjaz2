<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Document;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Tenancy\TenantContext::clear();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\ProvinceSeeder::class);
        $this->seed(\Database\Seeders\DemoSeeder::class);
    }

    public function test_guest_login_page(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/dashboard/login')->assertOk();

    }

    public function test_merchant_flow(): void
    {
        $merchant = User::where('username', 'تاجر')->first();
        $this->assertNotNull($merchant);

        $this->actingAs($merchant)->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/MerchantHome'));

        $this->actingAs($merchant)->get('/app/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/Orders'));

        $this->actingAs($merchant)->get('/app/wallet')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/Wallet'));

        $this->actingAs($merchant)->get('/app/chats')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/Chats'));

        $this->actingAs($merchant)->get('/app/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/Notifications'));

        $this->actingAs($merchant)->get('/app/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/Profile'));
    }

    public function test_courier_flow(): void
    {
        $courier = User::where('username', 'مندوب')->first();
        $this->assertNotNull($courier);

        $this->actingAs($courier)->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/CourierHome'));

        $this->actingAs($courier)->get('/app/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/Orders'));

        $this->actingAs($courier)->get('/app/wallet')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/Wallet'));
    }

    public function test_admin_flow(): void
    {
        $admin = User::where('role', 'admin')->first();
        $this->assertNotNull($admin);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Dashboard'));

        $this->actingAs($admin)->get('/dashboard/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Orders'));

        $this->actingAs($admin)->get('/dashboard/merchants')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Roster'));

        $this->actingAs($admin)->get('/dashboard/couriers')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Roster'));

        $this->actingAs($admin)->get('/dashboard/finance')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Finance'));

        $this->actingAs($admin)->get('/dashboard/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Notifications'));

        $this->actingAs($admin)->get('/dashboard/chat')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Chat'));
    }

    public function test_order_status_transition(): void
    {
        $merchant = User::where('username', 'تاجر')->first();
        $order = Order::first();

        $this->actingAs($merchant)->post("/app/orders/{$order->id}/status", ['status' => 'approved'])
            ->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'approved']);
    }

    public function test_wallet_withdraw_and_chat_send(): void
    {
        $courier = User::where('username', 'مندوب')->first();
        $merchant = User::where('username', 'تاجر')->first();

        $this->actingAs($courier)->post('/app/wallet/withdraw', ['amount' => 1000, 'gateway' => 'test'])
            ->assertRedirect();

        $chat = Chat::first();
        $this->assertNotNull($chat);

        $this->actingAs($merchant)->post("/app/chats/{$chat->id}/send", ['text' => 'hello smoke'])
            ->assertJson(['from_me' => true]);

        $this->actingAs($merchant)->get("/app/chats/{$chat->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/ChatThread'));
    }

    public function test_admin_user_status_change(): void
    {
        $admin = User::where('role', 'admin')->first();
        $merchant = User::where('username', 'تاجر')->first();

        $this->actingAs($admin)->post("/dashboard/users/{$merchant->id}/status", ['status' => 'suspended'])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $merchant->id, 'status' => 'suspended']);
    }
}
