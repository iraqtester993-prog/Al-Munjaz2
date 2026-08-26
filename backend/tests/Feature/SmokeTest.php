<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Branch;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->get('/pwa/manifest')
            ->assertOk()
            ->assertHeader('content-type', 'application/manifest+json; charset=utf-8');
        $this->get('/manifest.json')
            ->assertOk()
            ->assertHeader('content-type', 'application/manifest+json; charset=utf-8');
        $this->get('/pwa/worker')
            ->assertOk()
            ->assertHeader('content-type', 'application/javascript; charset=utf-8')
            ->assertSee('almunjaz-shell-v15');
        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('content-type', 'application/javascript; charset=utf-8')
            ->assertSee('almunjaz-shell-v15');
    }

    public function test_translation_dictionary_uses_the_selected_locale_before_the_fallback(): void
    {
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('ar');
            $this->assertSame('اضافة طلب جديد', app('translations')['Add New Order']);

            app()->setLocale('en');
            $this->assertSame('Add New Order', app('translations')['Add New Order']);

            app()->setLocale('ku');
            $this->assertSame('زیادکردنی داواکاری نوێ', app('translations')['Add New Order']);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_deployed_hosts_are_canonical_without_proxy_redirect_loops(): void
    {
        $original = [
            'env' => app()->environment(),
            'domain' => config('app.product_domain'),
            'mobile' => config('app.product_mobile_host'),
            'admin' => config('app.product_admin_host'),
        ];

        try {
            config([
                'app.product_domain' => 'our-qiq.com',
                'app.product_mobile_host' => 'mobile.our-qiq.com',
                'app.product_admin_host' => 'admin.our-qiq.com',
            ]);
            app()->instance('env', 'production');

            // PHP can receive an internal HTTP request after the HTTPS proxy.
            // The application must render it instead of looping on a redirect.
            $this->withServerVariables([
                'HTTPS' => 'off',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ])->get('http://mobile.our-qiq.com/login')->assertOk();

            $this->get('http://app.our-qiq.com/login')
                ->assertRedirect('https://mobile.our-qiq.com/login');

            $this->get('http://dashboard.our-qiq.com/dashboard/login')
                ->assertRedirect('https://admin.our-qiq.com/dashboard/login');

            $this->get('http://admin.our-qiq.com/app')
                ->assertRedirect('https://mobile.our-qiq.com/login');

            // The trusted-host allowlist rejects a forged Host before any
            // redirect can be constructed from it.
            $this->get('http://admin.evil.test/app')->assertStatus(400);
        } finally {
            config([
                'app.product_domain' => $original['domain'],
                'app.product_mobile_host' => $original['mobile'],
                'app.product_admin_host' => $original['admin'],
            ]);
            app()->instance('env', $original['env']);
            \Symfony\Component\HttpFoundation\Request::setTrustedHosts([]);
        }
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

        $this->actingAs($merchant)->get('/app/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Mobile/Reports'));

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

    public function test_mobile_notification_feed_returns_new_rows_and_the_total_unread_count(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $notification = Notification::create([
            'tenant_id' => $merchant->tenant_id,
            'user_id' => $merchant->id,
            'type' => 'account',
            'title_ar' => 'تحديث اختبار',
            'title_en' => 'Test update',
            'title_ku' => 'نوێکردنەوەی تاقیکاری',
            'body_ar' => 'ظهر الإشعار الجديد في صندوق الوارد.',
        ]);

        $response = $this->actingAs($merchant)
            ->getJson('/app/notifications/feed?after='.($notification->id - 1))
            ->assertOk()
            ->assertJsonPath('latest_id', $notification->id)
            ->assertJsonPath('notifications.0.id', $notification->id)
            ->assertJsonPath('notifications.0.read', false)
            ->assertJsonStructure(['notifications', 'unread', 'latest_id']);

        $this->assertGreaterThanOrEqual(1, $response->json('unread'));
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

        $this->actingAs($admin)->get('/dashboard/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Settings'));
    }

    public function test_admin_can_save_branding_and_send_general_or_targeted_notifications(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();

        $this->actingAs($admin)->post('/dashboard/settings', [
            'brand_name' => 'المنجز السريع',
            'brand_tagline' => 'منصة توصيل متكاملة',
            'support_phone' => '07700000000',
            'support_email' => 'support@our-qiq.com',
            'currency' => 'IQD',
            'delivery_fee' => 3500,
            'order_expiry_minutes' => 30,
            'pickup_eta_minutes' => 20,
        ])->assertRedirect();

        $this->assertSame('المنجز السريع', Setting::branding()['name']);
        $this->assertSame(3500, (int) Setting::get('delivery_fee'));

        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'user',
            'target_user_id' => $merchant->id,
            'type' => 'announcement',
            'title_ar' => 'إشعار اختبار',
            'title_en' => 'Test announcement',
            'title_ku' => 'ئاگادارکردنەوەی تاقیکاری',
            'body_ar' => 'وصل إشعار الإدارة إلى صندوق الوارد.',
            'body_en' => 'The dashboard delivered an inbox notification.',
            'body_ku' => 'ئاگادارکردنەوەکە گەیشتە ناو سندووقی هاتووەکان.',
        ])->assertRedirect();

        $this->assertDatabaseHas('notification_campaigns', [
            'audience' => 'user',
            'target_user_id' => $merchant->id,
            'recipient_count' => 1,
            'title_ar' => 'إشعار اختبار',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $merchant->id,
            'title_ar' => 'إشعار اختبار',
        ]);
    }

    public function test_mobile_user_can_store_a_browser_push_subscription_when_vapid_is_configured(): void
    {
        config([
            'services.web_push.subject' => 'https://our-qiq.com',
            'services.web_push.public_key' => 'test-public-key',
            'services.web_push.private_key' => 'test-private-key',
        ]);

        $merchant = User::where('username', 'تاجر')->firstOrFail();

        $this->actingAs($merchant)->getJson('/app/push/config')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('publicKey', 'test-public-key');

        $this->actingAs($merchant)->postJson('/app/push/subscriptions', [
            'endpoint' => 'https://push.example.test/subscription-1',
            'keys' => ['p256dh' => 'public-device-key', 'auth' => 'auth-device-key'],
            'locale' => 'ar',
        ])->assertOk()->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $merchant->id,
            'endpoint' => 'https://push.example.test/subscription-1',
            'locale' => 'ar',
        ]);
        $this->assertSame(1, PushSubscription::where('user_id', $merchant->id)->count());
    }

    public function test_new_orders_use_the_administrator_fee_and_availability_window(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        Setting::set('delivery_fee', 4750);
        Setting::set('order_expiry_minutes', 45);
        $startedAt = now();

        $this->actingAs($merchant)->post('/app/orders', [
            'customer_name_ar' => 'عميل إعدادات',
            'phone' => '07710009999',
            'address_ar' => 'بغداد — الكرادة',
            'province_id' => $province->id,
            'delivery_vehicle' => 'normal',
            'price' => 22000,
        ])->assertRedirect();

        $order = Order::query()->where('customer_name_ar', 'عميل إعدادات')->latest('id')->firstOrFail();
        $this->assertSame(4750, (int) $order->fee);
        $this->assertGreaterThanOrEqual($startedAt->copy()->addMinutes(44)->timestamp, $order->pickup_deadline_at->timestamp);
        $this->assertLessThanOrEqual($startedAt->copy()->addMinutes(46)->timestamp, $order->pickup_deadline_at->timestamp);
    }

    public function test_order_status_transition(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $order = Order::where('status', 'pending')->whereNull('courier_id')->firstOrFail();
        $startingBudget = $courier->wallet->budget;

        $this->actingAs($courier)->post("/app/orders/{$order->id}/claim")
            ->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'approved', 'courier_id' => $courier->id]);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'paid_order',
            'direction' => -1,
        ]);
        $this->assertSame($startingBudget - $order->price, $courier->wallet->fresh()->budget);

        $this->actingAs($courier)->post("/app/orders/{$order->id}/status", ['status' => 'courier'])
            ->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'courier', 'courier_id' => $courier->id]);
    }

    public function test_courier_must_be_on_duty_to_claim_an_order(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $courier->update(['is_online' => false]);
        $order = Order::where('status', 'pending')->whereNull('courier_id')->firstOrFail();

        $this->actingAs($courier)->post("/app/orders/{$order->id}/claim")
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'courier_id' => null,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'type' => 'paid_order',
        ]);
    }

    public function test_courier_can_switch_off_duty_without_a_heartbeat_reenabling_it(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();

        $this->actingAs($courier)->post('/app/duty', ['is_online' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $courier->id,
            'is_online' => false,
        ]);

        // A regular authenticated application request runs ActiveUserMiddleware.
        // It must refresh only the heartbeat, not silently put the courier back
        // on duty and make restricted orders claimable again.
        $this->actingAs($courier)->get('/app')->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $courier->id,
            'is_online' => false,
        ]);
    }

    public function test_admin_assignment_reserves_the_same_courier_budget(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $order = Order::where('status', 'pending')->whereNull('courier_id')->firstOrFail();
        $startingBudget = $courier->wallet->budget;

        $this->actingAs($admin)->post("/dashboard/orders/{$order->id}/courier", [
            'courier_id' => $courier->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'approved',
            'courier_id' => $courier->id,
        ]);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'paid_order',
            'direction' => -1,
        ]);
        $this->assertSame($startingBudget - $order->price, $courier->wallet->fresh()->budget);
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

    public function test_order_complaint_and_pending_order_protection(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $approved = Order::where('status', 'approved')->firstOrFail();
        $delivered = Order::where('status', 'delivered')->firstOrFail();

        $this->actingAs($merchant)->post('/app/chats/open', ['order_id' => $approved->id])
            ->assertRedirect();

        $this->assertDatabaseHas('chats', [
            'order_id' => $approved->id,
            'user_id' => $merchant->id,
            'counterparty_type' => 'order_support',
        ]);

        $this->actingAs($merchant)->post("/app/orders/{$delivered->id}/update", [])
            ->assertStatus(422);

        $this->actingAs($merchant)->post("/app/orders/{$approved->id}/status", ['status' => 'courier'])
            ->assertForbidden();
    }

    public function test_admin_can_set_an_order_branch_route(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $order = Order::where('status', 'pending')->firstOrFail();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $order->tenant_id)->firstOrFail();

        $this->actingAs($admin)->post("/dashboard/orders/{$order->id}/branches", [
            'origin_branch_id' => $branch->id,
            'destination_branch_id' => $branch->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'origin_branch_id' => $branch->id,
            'destination_branch_id' => $branch->id,
            'branch_id' => $branch->id,
            'workflow_stage' => 'at_origin_branch',
        ]);
    }

    public function test_admin_user_status_change(): void
    {
        $admin = User::where('role', 'admin')->first();
        $merchant = User::where('username', 'تاجر')->first();

        $this->actingAs($admin)->post("/dashboard/users/{$merchant->id}/status", ['status' => 'suspended'])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $merchant->id, 'status' => 'suspended']);
    }

    public function test_registration_uses_temporary_otp_then_enters_the_app_without_admin_review(): void
    {
        $province = \App\Models\Province::query()->whereNull('tenant_id')->firstOrFail();

        $this->post('/register', [
            'role' => 'merchant',
            'name' => 'تاجر OTP',
            'shop' => 'متجر OTP',
            'address' => 'بغداد',
            'province_id' => $province->id,
            'phone' => '07900001234',
            'password' => 'temporary-pass-123',
            'password_confirmation' => 'temporary-pass-123',
        ])->assertRedirect('/verify-otp');

        $this->get('/verify-otp')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Otp'));

        $this->post('/verify-otp', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->post('/verify-otp', ['code' => '123456'])
            ->assertRedirect('/app');

        $user = User::where('phone', '07900001234')->firstOrFail();
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_courier_registration_accepts_safe_documents_and_starts_otp_activation(): void
    {
        Storage::fake('public');
        $province = \App\Models\Province::query()->whereNull('tenant_id')->firstOrFail();

        $this->post('/register', [
            'role' => 'courier',
            'name' => 'مندوب وثائق',
            'address' => 'بغداد — الكرادة',
            'vehicle' => 'bike',
            'province_id' => $province->id,
            'phone' => '07900001235',
            'password' => 'temporary-pass-123',
            'password_confirmation' => 'temporary-pass-123',
            'residence_document' => UploadedFile::fake()->image('residence.jpg'),
            'id_front_document' => UploadedFile::fake()->image('id-front.jpg'),
            'id_back_document' => UploadedFile::fake()->image('id-back.jpg'),
            'license_front_document' => UploadedFile::fake()->image('license-front.jpg'),
            'license_back_document' => UploadedFile::fake()->image('license-back.jpg'),
        ])->assertRedirect('/verify-otp');

        $courier = User::where('phone', '07900001235')->firstOrFail();
        $this->assertSame('pending', $courier->status);
        $this->assertSame(5, Document::where('user_id', $courier->id)->count());
    }

    public function test_courier_registration_rejects_a_document_bundle_above_the_safe_request_limit(): void
    {
        $province = \App\Models\Province::query()->whereNull('tenant_id')->firstOrFail();

        $this->post('/register', [
            'role' => 'courier',
            'name' => 'مندوب كبير',
            'address' => 'بغداد — الكرادة',
            'vehicle' => 'bike',
            'province_id' => $province->id,
            'phone' => '07900001236',
            'password' => 'temporary-pass-123',
            'password_confirmation' => 'temporary-pass-123',
            // Each document is permitted individually (0.9 MB), but their
            // combined body would cross a typical shared-hosting limit.
            'residence_document' => UploadedFile::fake()->create('residence.pdf', 900, 'application/pdf'),
            'id_front_document' => UploadedFile::fake()->create('id-front.pdf', 900, 'application/pdf'),
            'id_back_document' => UploadedFile::fake()->create('id-back.pdf', 900, 'application/pdf'),
            'license_front_document' => UploadedFile::fake()->create('license-front.pdf', 900, 'application/pdf'),
            'license_back_document' => UploadedFile::fake()->create('license-back.pdf', 900, 'application/pdf'),
        ])->assertSessionHasErrors('documents');

        $this->assertDatabaseMissing('users', ['phone' => '07900001236']);
    }

    public function test_chat_messages_endpoint_refreshes_messages_for_the_other_party(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $chat = Chat::query()->with('user')->firstOrFail();
        $recipient = $chat->user;

        $this->actingAs($admin)->post("/dashboard/chat/{$chat->id}/send", ['text' => 'رسالة تحديث حي'])
            ->assertOk()
            ->assertJsonPath('from_me', true);

        $this->actingAs($recipient)->get("/app/chats/{$chat->id}/messages")
            ->assertOk()
            ->assertJsonFragment(['text' => 'رسالة تحديث حي', 'from_me' => false]);

        $this->assertNotNull($chat->fresh()->user_read_at);
    }

    public function test_admin_preference_routes_persist_theme_and_language(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin)->post('/dashboard/preferences/theme', ['theme' => 'dark'])->assertRedirect();
        $this->actingAs($admin)->post('/dashboard/preferences/locale', ['locale' => 'ku'])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'theme' => 'dark', 'locale' => 'ku']);
    }

    public function test_merchant_can_submit_profile_verification_without_losing_active_access(): void
    {
        Storage::fake('public');
        $merchant = User::where('username', 'تاجر')->firstOrFail();

        $this->actingAs($merchant)->post('/profile/verification', [
            'name' => 'Merchant Verified',
            'address' => 'Baghdad — Karrada',
            'phone' => '07900009991',
            'identity_number' => 'ID-TEST-123',
            'id_front_document' => UploadedFile::fake()->image('id-front.jpg'),
            'id_back_document' => UploadedFile::fake()->image('id-back.jpg'),
            'residence_document' => UploadedFile::fake()->image('residence-front.jpg'),
            'residence_back_document' => UploadedFile::fake()->image('residence-back.jpg'),
        ])->assertRedirect();

        $merchant->refresh();
        $this->assertSame('active', $merchant->status);
        $this->assertSame('ID-TEST-123', $merchant->identity_number);
        $this->assertDatabaseHas('documents', ['user_id' => $merchant->id, 'type' => 'id_front', 'status' => 'pending']);
        $this->assertDatabaseHas('documents', ['user_id' => $merchant->id, 'type' => 'residence_back', 'status' => 'pending']);
    }
}
