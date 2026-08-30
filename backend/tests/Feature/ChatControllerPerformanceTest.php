<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChatControllerPerformanceTest extends TestCase
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

    public function test_thread_history_and_incremental_messages_are_bounded_without_skipping_the_latest_rows(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $chat = Chat::query()->where('user_id', $merchant->id)->firstOrFail();
        $created = [];

        for ($sequence = 1; $sequence <= 205; $sequence++) {
            $created[] = ChatMessage::create([
                'chat_id' => $chat->id,
                'sender_id' => $merchant->id,
                'text' => "bounded-message-{$sequence}",
                'created_at' => now()->addSeconds($sequence),
            ]);
        }

        $this->actingAs($merchant)
            ->get("/app/chats/{$chat->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/ChatThread')
                ->has('messages', 100)
                ->where('messages.0.id', $created[105]->id)
                ->where('messages.99.id', $created[204]->id));

        $this->actingAs($merchant)
            ->getJson("/app/chats/{$chat->id}/messages?after_id={$created[0]->id}")
            ->assertOk()
            ->assertJsonCount(100, 'messages')
            ->assertJsonPath('messages.0.id', $created[1]->id)
            ->assertJsonPath('messages.99.id', $created[100]->id)
            ->assertJsonPath('last_id', $created[100]->id);
    }

    public function test_mobile_chat_list_uses_the_viewer_read_cursor_for_its_unread_aggregate(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();

        $chat = Chat::create([
            'tenant_id' => $merchant->tenant_id,
            'user_id' => $merchant->id,
            'counterparty_id' => $courier->id,
            'counterparty_type' => 'order_chat',
            'title_ar' => 'محادثة أداء',
            'last_at' => now()->addMinute(),
        ]);

        ChatMessage::create([
            'chat_id' => $chat->id,
            'sender_id' => $courier->id,
            'text' => 'رسالة غير مقروءة',
            'created_at' => now()->addSeconds(2),
        ]);

        $this->actingAs($merchant)
            ->get('/app/chats')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Chats')
                ->where('chats.0.id', $chat->id)
                ->where('chats.0.unread', 1));

        $chat->forceFill(['user_read_at' => now()->addMinutes(2)])->save();

        $this->actingAs($merchant)
            ->get('/app/chats')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('chats.0.id', $chat->id)
                ->where('chats.0.unread', 0));
    }

    public function test_idle_incremental_poll_does_not_rewrite_the_read_cursor(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $chat = Chat::query()->where('user_id', $merchant->id)->firstOrFail();
        $lastMessageId = (int) $chat->messages()->max('id');
        $readAt = now()->addMinute();
        $chat->forceFill(['user_read_at' => $readAt])->save();

        $this->actingAs($merchant)
            ->getJson("/app/chats/{$chat->id}/messages?after_id={$lastMessageId}")
            ->assertOk()
            ->assertJsonCount(0, 'messages')
            ->assertJsonPath('unread', 0);

        $this->assertSame(
            $readAt->format('Y-m-d H:i:s'),
            $chat->fresh()->user_read_at?->format('Y-m-d H:i:s'),
        );
    }
}
