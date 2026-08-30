<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminNotificationAudienceTargetingTest extends TestCase
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

    public function test_all_app_users_campaign_only_reaches_active_mobile_roles(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('role', 'merchant')->firstOrFail();
        $courier = User::where('role', 'courier')->firstOrFail();
        $extraMerchant = $this->makeUser('merchant', 'active', 'all-merchant', '07981000001');
        $pickupCourier = $this->makeUser('pickup_courier', 'active', 'all-pickup', '07981000002');
        $deliveryCourier = $this->makeUser('delivery_courier', 'active', 'all-delivery', '07981000003');
        $inactiveMerchant = $this->makeUser('merchant', 'suspended', 'all-suspended-merchant', '07981000004');
        $inactiveCourier = $this->makeUser('courier', 'pending', 'all-pending-courier', '07981000005');
        $owner = $this->makeUser('owner', 'active', 'all-owner', '07981000006');

        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'all',
            'type' => 'announcement',
            'title_ar' => 'تعميم مستخدمي التطبيق',
            'body_ar' => 'يصل فقط إلى حسابات التطبيق النشطة.',
        ])->assertRedirect();

        $campaign = NotificationCampaign::where('title_ar', 'تعميم مستخدمي التطبيق')->firstOrFail();
        $actualRecipientIds = Notification::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $expectedRecipientIds = User::query()
            ->whereIn('role', User::NOTIFICATION_RECIPIENT_ROLES)
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame($expectedRecipientIds, $actualRecipientIds);
        $this->assertSame(count($expectedRecipientIds), $campaign->recipient_count);
        $this->assertContains($merchant->id, $actualRecipientIds);
        $this->assertContains($courier->id, $actualRecipientIds);
        $this->assertContains($extraMerchant->id, $actualRecipientIds);
        $this->assertContains($pickupCourier->id, $actualRecipientIds);
        $this->assertContains($deliveryCourier->id, $actualRecipientIds);
        $this->assertNotContains($inactiveMerchant->id, $actualRecipientIds);
        $this->assertNotContains($inactiveCourier->id, $actualRecipientIds);
        $this->assertNotContains($owner->id, $actualRecipientIds);
        $this->assertNotContains($admin->id, $actualRecipientIds);
    }

    public function test_dashboard_can_target_one_active_merchant_and_keeps_a_campaign_history_record(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = $this->makeUser('merchant', 'active', 'target-merchant', '07981000101');

        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'merchant',
            'target_user_id' => $merchant->id,
            'type' => 'account',
            'title_ar' => 'إشعار للتاجر المحدد',
            'body_ar' => 'تصل هذه الرسالة إلى التاجر المختار فقط.',
        ])->assertRedirect();

        $campaign = NotificationCampaign::where('title_ar', 'إشعار للتاجر المحدد')->firstOrFail();
        $this->assertSame('merchant', $campaign->audience);
        $this->assertSame($merchant->id, $campaign->target_user_id);
        $this->assertSame(1, $campaign->recipient_count);
        $this->assertDatabaseHas('notifications', [
            'campaign_id' => $campaign->id,
            'user_id' => $merchant->id,
            'title_ar' => 'إشعار للتاجر المحدد',
        ]);

        $this->actingAs($admin)->get('/dashboard/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Notifications')
                ->has('campaigns', 1)
                ->where('campaigns.0.id', $campaign->id)
                ->where('campaigns.0.audience', 'merchant')
                ->where('campaigns.0.target_user_id', $merchant->id)
                ->where('campaigns.0.target_user.name', $merchant->name)
                ->where('campaigns.0.delivery_count', 1));
    }

    public function test_selected_account_must_be_active_and_match_the_requested_merchant_or_courier_audience(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = $this->makeUser('merchant', 'active', 'target-match-merchant', '07981000201');
        $suspendedMerchant = $this->makeUser('merchant', 'suspended', 'target-suspended-merchant', '07981000202');
        $pickupCourier = $this->makeUser('pickup_courier', 'active', 'target-pickup-courier', '07981000203');

        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'merchant',
            'target_user_id' => $pickupCourier->id,
            'type' => 'announcement',
            'title_ar' => 'استهداف خاطئ',
        ])->assertSessionHasErrors('target_user_id');

        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'courier',
            'target_user_id' => $merchant->id,
            'type' => 'announcement',
            'title_ar' => 'استهداف خاطئ ثانٍ',
        ])->assertSessionHasErrors('target_user_id');

        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'merchant',
            'target_user_id' => $suspendedMerchant->id,
            'type' => 'announcement',
            'title_ar' => 'استهداف حساب غير فعال',
        ])->assertSessionHasErrors('target_user_id');

        // A previously selected account must never be silently ignored and
        // converted into a general merchant broadcast.
        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'merchants',
            'target_user_id' => $merchant->id,
            'type' => 'announcement',
            'title_ar' => 'تعميم غير مقصود',
        ])->assertSessionHasErrors('target_user_id');

        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'courier',
            'target_user_id' => $pickupCourier->id,
            'type' => 'order',
            'title_ar' => 'إشعار لمندوب استلام',
        ])->assertRedirect();

        $campaign = NotificationCampaign::where('title_ar', 'إشعار لمندوب استلام')->firstOrFail();
        $this->assertSame('courier', $campaign->audience);
        $this->assertSame($pickupCourier->id, $campaign->target_user_id);
        $this->assertSame(1, $campaign->recipient_count);
        $this->assertDatabaseHas('notifications', [
            'campaign_id' => $campaign->id,
            'user_id' => $pickupCourier->id,
        ]);
        $this->assertSame(1, NotificationCampaign::query()->count());
    }

    private function makeUser(string $role, string $status, string $username, string $phone): User
    {
        $merchant = User::where('role', 'merchant')->firstOrFail();

        return User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'حساب إشعار '.$username,
            'username' => $username,
            'phone' => $phone,
            'password' => 'StrongPassword123!',
            'role' => $role,
            'status' => $status,
        ]);
    }
}
