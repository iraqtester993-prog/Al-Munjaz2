<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
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

    public function test_dashboard_separates_support_from_read_only_merchant_courier_chats(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $order = Order::where('status', 'approved')
            ->where('courier_id', $courier->id)
            ->firstOrFail();

        $support = Chat::withoutGlobalScope(TenantScope::class)
            ->where('counterparty_type', 'support')
            ->sole();

        // A complaint belongs in the operations inbox. Its counterparty is
        // only an order-context snapshot; the merchant and an administrator
        // are the actual participants.
        $complaint = Chat::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $merchant->id,
            'counterparty_id' => $courier->id,
            'counterparty_type' => 'order_support',
            'order_id' => $order->id,
            'title_ar' => 'شكوى / تأخر — '.$courier->name,
            'last_message' => 'أحتاج مساعدة من الإدارة.',
            'last_at' => now()->subMinute(),
        ]);

        // This direct conversation is separately listed for operational
        // review. It remains read-only to dashboard staff, so the dashboard
        // never becomes a hidden third participant.
        $directChat = Chat::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $merchant->id,
            'counterparty_id' => $courier->id,
            'counterparty_type' => 'order_chat',
            'order_id' => $order->id,
            'title_ar' => 'محادثة الطلب — '.$order->track_no,
            'last_message' => 'رسالة خاصة بين التاجر والمندوب.',
            'last_at' => now()->addMinute(),
        ]);

        ChatMessage::create([
            'chat_id' => $directChat->id,
            'sender_id' => $merchant->id,
            'text' => 'رسالة التاجر للمندوب.',
            'created_at' => now()->subSeconds(10),
        ]);
        ChatMessage::create([
            'chat_id' => $directChat->id,
            'sender_id' => $courier->id,
            'text' => 'رسالة المندوب للتاجر.',
            'created_at' => now(),
        ]);

        // A historical direct-chat marker must fail closed too; the inbox
        // allows support types rather than attempting to exclude one name.
        $legacyDirectChat = Chat::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $merchant->id,
            'counterparty_id' => $courier->id,
            'counterparty_type' => 'courier',
            'order_id' => $order->id,
            'title_ar' => 'محادثة تشغيلية قديمة',
            'last_message' => 'رسالة خاصة قديمة.',
            'last_at' => now()->addMinutes(2),
        ]);

        $response = $this->actingAs($admin)
            ->get('/dashboard/chat')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Chat')
                // The legacy prop remains support-only for the existing
                // client, while the two explicit tab payloads are supplied
                // for the dashboard's new chat UI.
                ->has('chats', 2)
                ->has('supportChats', 2)
                ->has('merchantCourierChats', 1)
                ->has('chatTabs', 2)
                ->where('merchantCourierChats.0.id', $directChat->id)
                ->where('merchantCourierChats.0.channel', 'merchant_courier')
                ->where('merchantCourierChats.0.read_only', true)
                ->where('merchantCourierChats.0.can_reply', false)
                ->where('merchantCourierChats.0.merchant_name', $merchant->name)
                ->where('merchantCourierChats.0.courier_name', $courier->name)
                ->where('merchantCourierChats.0.track_no', $order->track_no)
                ->where('merchantCourierChats.0.tracking_no', $order->track_no));

        $visibleIds = collect($response->inertiaProps('chats'))->pluck('id')->all();
        $this->assertContains($support->id, $visibleIds);
        $this->assertContains($complaint->id, $visibleIds);
        $this->assertNotContains($directChat->id, $visibleIds);
        $this->assertNotContains($legacyDirectChat->id, $visibleIds);
        $this->assertSame(2, $response->inertiaProps('adminBadges.chat'));

        $this->actingAs($admin)
            ->get("/dashboard/chat/{$support->id}")
            ->assertOk();

        $this->actingAs($admin)
            ->get("/dashboard/chat/{$directChat->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Chat')
                ->where('activeChat.id', $directChat->id)
                ->where('activeChat.channel', 'merchant_courier')
                ->where('activeChat.merchant.name', $merchant->name)
                ->where('activeChat.courier.name', $courier->name)
                ->where('activeChat.order.track_no', $order->track_no)
                ->where('messages.0.sender_name', $merchant->name)
                ->where('messages.0.sender_role', 'merchant')
                ->where('messages.1.sender_name', $courier->name)
                ->where('messages.1.sender_role', 'courier'));

        $this->actingAs($admin)
            ->get("/dashboard/chat/{$directChat->id}/messages")
            ->assertOk()
            ->assertJsonPath('messages.0.sender_name', $merchant->name)
            ->assertJsonPath('messages.0.sender_role', 'merchant')
            ->assertJsonPath('messages.1.sender_name', $courier->name)
            ->assertJsonPath('messages.1.sender_role', 'courier');

        $this->actingAs($admin)
            ->post("/dashboard/chat/{$directChat->id}/send", ['text' => 'لا يجب إرسال هذه الرسالة.'])
            ->assertNotFound();

        // Unknown direct-style legacy rows still fail closed.
        $this->actingAs($admin)
            ->get("/dashboard/chat/{$legacyDirectChat->id}")
            ->assertNotFound();
        $this->actingAs($admin)
            ->get("/dashboard/chat/{$legacyDirectChat->id}/messages")
            ->assertNotFound();
        $this->actingAs($admin)
            ->post("/dashboard/chat/{$legacyDirectChat->id}/send", ['text' => 'لا يجب إرسال هذه الرسالة.'])
            ->assertNotFound();

        // Opening the read-only audit view records an admin read cursor,
        // whereas an excluded legacy conversation remains untouched.
        $this->assertNotNull($directChat->fresh()->admin_read_at);
        $this->assertNull($legacyDirectChat->fresh()->admin_read_at);
    }
}
