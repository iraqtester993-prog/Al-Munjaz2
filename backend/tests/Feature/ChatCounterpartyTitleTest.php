<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChatCounterpartyTitleTest extends TestCase
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

    public function test_order_chat_and_complaint_identify_the_counterparty_and_order(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $order = Order::where('status', 'approved')
            ->where('courier_id', $courier->id)
            ->firstOrFail();

        $this->actingAs($merchant)
            ->post('/app/chats/open', ['order_id' => $order->id])
            ->assertRedirect();

        $chat = Chat::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('counterparty_type', 'order_chat')
            ->firstOrFail();

        // A merchant needs to see the assigned courier, while that courier
        // needs to see the merchant. The shared chat record itself must not
        // force the same label on both participants.
        $this->actingAs($merchant)
            ->get("/app/chats/{$chat->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/ChatThread')
                ->where('chat.counterparty_name', $courier->name)
                ->where('chat.title_ar', 'محادثة مع '.$courier->name.' — '.$order->track_no));

        $this->actingAs($courier)
            ->get("/app/chats/{$chat->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/ChatThread')
                ->where('chat.counterparty_name', $merchant->name)
                ->where('chat.title_ar', 'محادثة مع '.$merchant->name.' — '.$order->track_no));

        $this->actingAs($merchant)
            ->post('/app/chats/open', ['order_id' => $order->id, 'complaint' => true])
            ->assertRedirect();

        $complaint = Chat::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('user_id', $merchant->id)
            ->where('counterparty_type', 'order_support')
            ->firstOrFail();

        $this->actingAs($merchant)
            ->get("/app/chats/{$complaint->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/ChatThread')
                ->where('chat.counterparty_name', $courier->name)
                ->where('chat.title_ar', 'شكوى / تأخر — '.$courier->name.' — '.$order->track_no));
    }
}
