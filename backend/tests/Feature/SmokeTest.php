<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Chat;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderMovement;
use App\Models\OrderStatusLog;
use App\Models\Province;
use App\Models\PushSubscription;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;

class SmokeTest extends TestCase
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

    public function test_guest_login_page(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/dashboard/login')->assertOk();
        $manifest = json_decode((string) file_get_contents(resource_path('pwa/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('standalone', $manifest['display'] ?? null);
        $this->assertSame('maskable', $manifest['icons'][2]['purpose'] ?? null);

        $this->get('/pwa/manifest')
            ->assertOk()
            ->assertHeader('content-type', 'application/manifest+json; charset=utf-8');
        $this->get('/manifest.json')
            ->assertOk()
            ->assertHeader('content-type', 'application/manifest+json; charset=utf-8');
        $this->get('/pwa/worker')
            ->assertOk()
            ->assertHeader('content-type', 'application/javascript; charset=utf-8')
            ->assertSee('almunjaz-shell-'.config('app.pwa_version'))
            ->assertSee("const OFFLINE_PAGE = '/pwa/offline'", false);
        $this->assertStringContainsString('لا يوجد اتصال بالإنترنت', (string) file_get_contents(resource_path('pwa/offline.html')));
        $this->get('/pwa/offline')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=utf-8');
        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('content-type', 'application/javascript; charset=utf-8')
            ->assertSee('almunjaz-shell-'.config('app.pwa_version'));
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
            Request::setTrustedHosts([]);
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

    public function test_mobile_home_cards_expose_all_localized_text_for_english_and_kurdish(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-LOCALIZED-HOME',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل الصفحة الرئيسية',
            'customer_name_en' => 'Home Card Customer',
            'phone' => '07710008888',
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => 52000,
            'fee' => 3000,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'date' => today(),
        ]);

        $merchant->update(['locale' => 'en']);
        $this->actingAs($merchant)->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/MerchantHome')
                ->where('locale', 'en')
                ->where('recentOrders.0.id', $order->id)
                ->where('recentOrders.0.customer_name_en', 'Home Card Customer')
                ->where('recentOrders.0.customer_name_ku', 'Home Card Customer')
                ->where('recentOrders.0.address_en', 'Baghdad — Karrada')
                ->where('recentOrders.0.address_ku', 'Baghdad — Karrada')
                ->where('heroSlides.0.title_en', 'Track every order in real time')
                ->where('heroSlides.0.title_ku', 'هەر داواکارییەک بە ڕاستەوخۆ بەدواداچوون بکە')
                ->where('heroSlides.0.body_ku', 'لە تەبی داواکارییەکان شوێنی ڕاستەقینەی داواکارییەکەت بزانە')
            );

        $courier->update(['locale' => 'ku']);
        $this->actingAs($courier)->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/CourierHome')
                ->where('locale', 'ku')
                ->where('availableOrders.0.id', $order->id)
                ->where('availableOrders.0.customer_name_en', 'Home Card Customer')
                ->where('availableOrders.0.customer_name_ku', 'Home Card Customer')
                ->where('availableOrders.0.address_en', 'Baghdad — Karrada')
                ->where('availableOrders.0.address_ku', 'Baghdad — Karrada')
                ->where('heroSlides.0.title_en', 'Enable GPS for accurate delivery')
                ->where('heroSlides.0.title_ku', 'GPS چالاک بکە بۆ گەیاندنی وردتر')
                ->where('heroSlides.0.body_ku', 'یارمەتی لقەکە دەدات گەشتەکەت بە ڕاستەوخۆ بەدواداچوون بکات')
            );
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

        $this->actingAs($admin)->get('/dashboard/transfers')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Transfers'));

        $this->actingAs($admin)->get('/dashboard/cashboxes')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Cashboxes'));

        $this->actingAs($admin)->get('/dashboard/pricing')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Pricing'));

        $this->actingAs($admin)->get('/dashboard/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Reports'));

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

    public function test_admin_orders_expose_a_complete_operational_detail_payload(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $branch = Branch::where('tenant_id', $merchant->tenant_id)->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();

        $order = Order::create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-OPERATIONS-DETAIL',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل تفصيلي',
            'customer_name_en' => 'Detail Customer',
            'phone' => '07710009990',
            'phone2' => '07710009991',
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'order_type' => 'منتج',
            'delivery_vehicle' => 'sedan',
            'price' => 45000,
            'fee' => 3000,
            'status' => 'approved',
            'workflow_stage' => 'at_origin_branch',
            'origin_branch_id' => $branch->id,
            'destination_branch_id' => $branch->id,
            'branch_id' => $branch->id,
            'province_id' => $province->id,
            'date' => today(),
            'notes' => 'ملاحظة تشغيلية للاختبار',
        ]);

        OrderStatusLog::create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'approved',
            'user_id' => $admin->id,
            'note' => 'تمت مراجعة الطلب',
        ]);

        OrderMovement::create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'from_branch_id' => $branch->id,
            'to_branch_id' => $branch->id,
            'actor_id' => $admin->id,
            'stage' => 'at_origin_branch',
            'note' => 'تم تثبيت الطلب في فرع الانطلاق',
            'occurred_at' => now()->addMinute(),
        ]);

        $this->actingAs($admin)->get('/dashboard/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->where('orders.data.0.track_no', $order->track_no)
                ->where('orders.data.0.customer.name', 'عميل تفصيلي')
                ->where('orders.data.0.customer.phone2', '07710009991')
                ->where('orders.data.0.merchant.id', $merchant->id)
                ->where('orders.data.0.financial.order_value', 45000)
                ->where('orders.data.0.financial.delivery_fee', 3000)
                ->where('orders.data.0.financial.net_to_merchant', 42000)
                ->where('orders.data.0.origin_branch.id', $branch->id)
                ->where('orders.data.0.destination_branch.id', $branch->id)
                ->where('orders.data.0.timeline.0.kind', 'movement')
                ->where('orders.data.0.timeline.0.stage', 'at_origin_branch')
                ->has('orders.data.0.timeline', 3));
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
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => 'متجر الاختبار — الكرادة',
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

    public function test_mobile_order_payload_uses_the_real_branch_route_and_operational_history_for_each_authorized_role(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $platform = Tenant::platform();

        $origin = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'code' => 'MOBILE-ORIGIN',
            'name_ar' => 'فرع استلام التطبيق',
            'name_en' => 'Mobile Pickup Branch',
            'name_ku' => 'لقی وەرگرتنی ئەپ',
            'city' => 'بغداد',
            'is_active' => true,
        ]);
        $destination = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => 'MOBILE-DESTINATION',
            'name_ar' => 'فرع توصيل الشبكة',
            'name_en' => 'Network Delivery Branch',
            'name_ku' => 'لقی گەیاندنی تۆڕ',
            'city' => 'البصرة',
            'is_active' => true,
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-MOBILE-TIMELINE',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل مسار التطبيق',
            'customer_name_en' => 'Mobile Route Customer',
            'phone' => '07710007777',
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => 36000,
            'fee' => 3000,
            'status' => 'approved',
            // This stage is assigned by the route workflow. It must be sent
            // as-is, rather than inferred from the simplified status badge.
            'workflow_stage' => 'awaiting_transfer',
            'courier_id' => $courier->id,
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'branch_id' => $destination->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);

        $created = OrderStatusLog::create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'pending',
            'user_id' => $merchant->id,
        ]);
        $created->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $approved = OrderStatusLog::create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'approved',
            'user_id' => $admin->id,
            'note' => 'تم اعتماد الطلب',
        ]);
        $approved->forceFill(['created_at' => now()->subMinutes(20)])->save();

        OrderMovement::withoutGlobalScopes()->create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'from_branch_id' => $origin->id,
            'to_branch_id' => $destination->id,
            'actor_id' => $admin->id,
            'stage' => 'awaiting_transfer',
            'note' => 'تم إرسال الطلب إلى مسار النقل بين الفروع',
            'occurred_at' => now()->subMinutes(10),
        ]);

        $mobilePayload = fn (Assert $page) => $page
            ->component('Mobile/Orders')
            ->where('orders.0.track_no', $order->track_no)
            ->where('orders.0.workflow_stage', 'awaiting_transfer')
            ->where('orders.0.origin_branch.id', $origin->id)
            ->where('orders.0.origin_branch.name_en', 'Mobile Pickup Branch')
            ->where('orders.0.destination_branch.id', $destination->id)
            ->where('orders.0.destination_branch.name_ku', 'لقی گەیاندنی تۆڕ')
            ->where('orders.0.timeline.0.kind', 'movement')
            ->where('orders.0.timeline.0.stage', 'awaiting_transfer')
            ->where('orders.0.timeline.0.from_branch.id', $origin->id)
            ->where('orders.0.timeline.0.to_branch.id', $destination->id)
            ->where('orders.0.timeline.0.actor.id', $admin->id)
            ->has('orders.0.timeline', 3);

        // Both parties can read only their own operational order. The courier
        // needs the same shared branch path even though it belongs to the
        // merchant tenant, while unassigned orders remain excluded elsewhere
        // by CourierOrderAccess.
        $this->actingAs($merchant)->get('/app/orders')
            ->assertOk()
            ->assertInertia($mobilePayload);

        $this->actingAs($courier)->get('/app/orders')
            ->assertOk()
            ->assertInertia($mobilePayload);
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
        $pending = Order::where('status', 'pending')->firstOrFail();
        $approved = Order::where('status', 'approved')->firstOrFail();
        $delivered = Order::where('status', 'delivered')->firstOrFail();

        // A non-assigned order has no courier counterpart yet, so the
        // existing operations-support conversation remains available.
        $this->actingAs($merchant)->post('/app/chats/open', ['order_id' => $pending->id])
            ->assertRedirect();

        $this->assertDatabaseHas('chats', [
            'order_id' => $pending->id,
            'user_id' => $merchant->id,
            'counterparty_type' => 'order_support',
        ]);

        $this->actingAs($merchant)->post("/app/orders/{$delivered->id}/update", [])
            ->assertStatus(422);

        $this->actingAs($merchant)->post("/app/orders/{$approved->id}/status", ['status' => 'courier'])
            ->assertForbidden();
    }

    public function test_assigned_order_has_one_shared_direct_chat_for_merchant_and_courier(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $order = Order::where('status', 'approved')
            ->where('courier_id', $courier->id)
            ->firstOrFail();

        // Either participant can open the same order conversation.  It is
        // not two owner-specific support chats.
        $this->actingAs($merchant)->post('/app/chats/open', ['order_id' => $order->id])
            ->assertRedirect();

        $chat = Chat::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('counterparty_type', 'order_chat')
            ->firstOrFail();

        $this->assertSame($merchant->id, $chat->user_id);
        $this->assertSame($courier->id, $chat->counterparty_id);

        $this->actingAs($courier)->post('/app/chats/open', ['order_id' => $order->id])
            ->assertRedirect();

        $this->assertSame(1, Chat::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('counterparty_type', 'order_chat')
            ->count());

        $this->actingAs($merchant)->post("/app/chats/{$chat->id}/send", ['text' => 'تم تجهيز الطلب للاستلام'])
            ->assertOk()
            ->assertJsonPath('from_me', true);

        // This is the important cross-tenant path: the courier can resolve,
        // read, and reply to a chat owned by the merchant tenant.
        $this->actingAs($courier)->get("/app/chats/{$chat->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/ChatThread')
                ->where('chat.counterparty_type', 'order_chat')
                ->where('chat.order_id', $order->id));

        $this->actingAs($courier)->get("/app/chats/{$chat->id}/messages")
            ->assertOk()
            ->assertJsonFragment(['text' => 'تم تجهيز الطلب للاستلام', 'from_me' => false]);

        $this->actingAs($courier)->post("/app/chats/{$chat->id}/send", ['text' => 'أنا في الطريق'])
            ->assertOk()
            ->assertJsonPath('from_me', true);

        $this->actingAs($merchant)->get("/app/chats/{$chat->id}/messages")
            ->assertOk()
            ->assertJsonFragment(['text' => 'أنا في الطريق', 'from_me' => false]);

        $outsider = User::create([
            'tenant_id' => $courier->tenant_id,
            'name' => 'مندوب غير مكلّف',
            'username' => 'courier-outsider',
            'phone' => '07900009999',
            'password' => 'password',
            'role' => 'courier',
            'status' => 'active',
        ]);

        $this->actingAs($outsider)->get("/app/chats/{$chat->id}")
            ->assertForbidden();

        $this->assertNotNull($chat->fresh()->user_read_at);
        $this->assertNotNull($chat->fresh()->counterparty_read_at);
    }

    public function test_api_allows_the_assigned_courier_to_read_a_direct_order_chat_only(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $order = Order::where('status', 'approved')
            ->where('courier_id', $courier->id)
            ->firstOrFail();

        $chat = Chat::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $merchant->id,
            'counterparty_id' => $courier->id,
            'counterparty_type' => 'order_chat',
            'order_id' => $order->id,
            'title_ar' => 'محادثة الطلب — '.$order->track_no,
            'last_at' => now(),
        ]);

        Sanctum::actingAs($courier);
        $this->getJson("/api/v1/chats/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('data.counterparty_type', 'order_chat')
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.track_no', $order->track_no);

        $outsider = User::create([
            'tenant_id' => $courier->tenant_id,
            'name' => 'مندوب API غير مكلّف',
            'username' => 'api-courier-outsider',
            'phone' => '07900008888',
            'password' => 'password',
            'role' => 'courier',
            'status' => 'active',
        ]);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/chats/{$chat->id}")
            ->assertForbidden();
    }

    public function test_admin_created_network_branches_can_be_assigned_to_a_merchant_order(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $order = Order::where('status', 'pending')->firstOrFail();
        // Baghdad is already represented by the seeded operating branch.
        // A platform branch is deliberately one-per-governorate, so this test
        // creates two distinct operational areas rather than bypassing the
        // branch/province invariant.
        $originProvince = Province::where('name_ar', 'البصرة')->firstOrFail();
        $destinationProvince = Province::where('name_ar', 'أربيل')->firstOrFail();

        $this->actingAs($admin)->post('/dashboard/branches', [
            'code' => 'OPS-ORIGIN',
            'name_ar' => 'فرع شبكة الاستلام',
            'city' => 'البصرة',
            'province_id' => $originProvince->id,
            'phone' => '07710000000',
        ])->assertRedirect();

        $this->actingAs($admin)->post('/dashboard/branches', [
            'code' => 'OPS-DESTINATION',
            'name_ar' => 'فرع شبكة التوصيل',
            'city' => 'أربيل',
            'province_id' => $destinationProvince->id,
            'phone' => '07720000000',
        ])->assertRedirect();

        $origin = Branch::withoutGlobalScopes()->where('code', 'OPS-ORIGIN')->firstOrFail();
        $destination = Branch::withoutGlobalScopes()->where('code', 'OPS-DESTINATION')->firstOrFail();

        $this->assertTrue($origin->is_platform_managed);
        $this->assertTrue($destination->is_platform_managed);
        $this->assertSame(Tenant::PLATFORM_SLUG, $origin->tenant->slug);

        $this->actingAs($admin)->post("/dashboard/orders/{$order->id}/branches", [
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'branch_id' => $destination->id,
            'workflow_stage' => 'awaiting_transfer',
        ]);
        $this->assertDatabaseHas('order_movements', [
            'order_id' => $order->id,
            'from_branch_id' => $origin->id,
            'to_branch_id' => $destination->id,
            'stage' => 'awaiting_transfer',
        ]);

        // The merchant's tenant scope must still resolve an explicitly
        // assigned platform branch for its own order.
        TenantContext::setFromId($order->tenant_id);
        try {
            $merchantOrder = Order::findOrFail($order->id);
            $this->assertSame($origin->id, $merchantOrder->originBranch->id);
            $this->assertSame($destination->id, $merchantOrder->destinationBranch->id);
        } finally {
            TenantContext::clear();
        }
    }

    public function test_admin_can_manage_platform_branch_details_and_safe_activation(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $platform = Tenant::platform();
        $province = Province::where('name_ar', 'البصرة')->firstOrFail();
        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => 'OPS-MANAGE',
            'name_ar' => 'فرع العمليات',
            'name_en' => 'Operations Branch',
            'name_ku' => 'لقی کارپێکردن',
            'city' => 'البصرة',
            'province_id' => $province->id,
            'phone' => '07710000111',
            'address' => 'الكرادة',
            'is_active' => true,
        ]);
        $outbound = Order::where('status', 'pending')->firstOrFail();
        $inbound = Order::where('status', 'approved')->firstOrFail();
        $outbound->update(['origin_branch_id' => $branch->id]);
        $inbound->update(['destination_branch_id' => $branch->id]);

        $response = $this->actingAs($admin)->get('/dashboard/branches')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Branches')
                ->has('branches', 2)
            );

        $branchPayload = collect($response->inertiaProps('branches'))->firstWhere('id', $branch->id);
        $this->assertNotNull($branchPayload);
        $this->assertSame(1, data_get($branchPayload, 'outbound_orders_count'));
        $this->assertSame(1, data_get($branchPayload, 'inbound_orders_count'));
        $this->assertSame('Operations Branch', data_get($branchPayload, 'name_en'));
        $this->assertSame('لقی کارپێکردن', data_get($branchPayload, 'name_ku'));

        $this->actingAs($admin)->put("/dashboard/branches/{$branch->id}", [
            'code' => 'ops-manage-2',
            'name_ar' => 'فرع عمليات بغداد',
            'name_en' => 'Baghdad Operations Branch',
            'name_ku' => 'لقی کارپێکردنی بەغدا',
            'city' => 'بغداد',
            'province_id' => $province->id,
            'phone' => '07710000222',
            'address' => 'الكرادة — شارع 52',
        ])->assertRedirect();

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'code' => 'OPS-MANAGE-2',
            'name_en' => 'Baghdad Operations Branch',
            'name_ku' => 'لقی کارپێکردنی بەغدا',
            'phone' => '07710000222',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'branch.updated',
            'subject_id' => $branch->id,
        ]);

        // A network branch cannot be taken offline while an open route still
        // depends on it. This prevents a live order from losing its branch.
        $this->actingAs($admin)->patch("/dashboard/branches/{$branch->id}/status", ['is_active' => false])
            ->assertRedirect()
            ->assertSessionHasErrors('is_active');

        $outbound->update(['status' => 'delivered']);
        $inbound->update(['status' => 'returned']);

        $this->actingAs($admin)->patch("/dashboard/branches/{$branch->id}/status", ['is_active' => false])
            ->assertRedirect();
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => false]);

        $this->actingAs($admin)->patch("/dashboard/branches/{$branch->id}/status", ['is_active' => true])
            ->assertRedirect();
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => true]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'branch.status_updated',
            'subject_id' => $branch->id,
        ]);
    }

    public function test_admin_cannot_edit_or_toggle_a_merchant_private_branch(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'is_platform_managed' => false,
            'code' => 'PRIVATE-ONLY',
            'name_ar' => 'فرع خاص',
            'city' => 'بغداد',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put("/dashboard/branches/{$branch->id}", [
            'code' => 'PRIVATE-EDIT',
            'name_ar' => 'محاولة تعديل',
            'city' => 'بغداد',
        ])->assertNotFound();

        $this->actingAs($admin)->patch("/dashboard/branches/{$branch->id}/status", ['is_active' => false])
            ->assertNotFound();

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'code' => 'PRIVATE-ONLY',
            'is_active' => true,
        ]);
    }

    public function test_branch_assignment_rejects_inactive_or_foreign_private_branches(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $order = Order::where('status', 'pending')->firstOrFail();
        $platform = Tenant::platform();

        $inactiveNetworkBranch = Branch::create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => 'OPS-INACTIVE',
            'name_ar' => 'فرع شبكة موقوف',
            'city' => 'بغداد',
            'is_active' => false,
        ]);
        $otherTenant = Tenant::create([
            'slug' => 'other-merchant-branch-test',
            'name' => 'تاجر آخر',
            'kind' => 'merchant',
            'status' => 'active',
        ]);
        $foreignPrivateBranch = Branch::create([
            'tenant_id' => $otherTenant->id,
            'is_platform_managed' => false,
            'code' => 'OTHER-PRIVATE',
            'name_ar' => 'فرع خاص بتاجر آخر',
            'city' => 'أربيل',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post("/dashboard/orders/{$order->id}/branches", [
            'origin_branch_id' => $inactiveNetworkBranch->id,
        ])->assertStatus(422);

        $this->actingAs($admin)->post("/dashboard/orders/{$order->id}/branches", [
            'origin_branch_id' => $foreignPrivateBranch->id,
        ])->assertStatus(422);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'origin_branch_id' => null,
            'destination_branch_id' => null,
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
        $province = Province::query()->whereNull('tenant_id')->firstOrFail();

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
        $province = Province::query()->whereNull('tenant_id')->firstOrFail();

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
        $province = Province::query()->whereNull('tenant_id')->firstOrFail();

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
