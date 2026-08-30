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
use Laravel\Sanctum\Sanctum;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserNotificationDeletionTest extends TestCase
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

    public function test_user_can_soft_delete_only_their_delivery_without_touching_campaign_or_other_deliveries(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $otherMerchant = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'تاجر إشعارات آخر',
            'username' => 'other-inbox-merchant',
            'phone' => '07981000991',
            'password' => 'StrongPassword123!',
            'role' => 'merchant',
            'status' => 'active',
        ]);
        $campaign = $this->campaign(2);
        $mine = $this->delivery($campaign, $merchant);
        $other = $this->delivery($campaign, $otherMerchant);

        $this->actingAs($merchant)
            ->from('/app/notifications')
            ->delete("/app/notifications/{$mine->id}")
            ->assertRedirect('/app/notifications');

        $this->assertSoftDeleted('notifications', ['id' => $mine->id]);
        $this->assertNotSoftDeleted('notifications', ['id' => $other->id]);
        $this->assertDatabaseHas('notification_campaigns', [
            'id' => $campaign->id,
            'recipient_count' => 2,
        ]);

        $this->actingAs($otherMerchant)
            ->getJson('/app/notifications/feed')
            ->assertOk()
            ->assertJsonPath('notifications.0.id', $other->id);

        // The dashboard is an audit trail of sends. A recipient removing one
        // inbox item must not make the campaign appear to have fewer
        // deliveries than were originally issued.
        $admin = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($admin)
            ->get('/dashboard/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Notifications')
                ->where('campaigns.0.id', $campaign->id)
                ->where('campaigns.0.delivery_count', 2)
                ->where('counts.deliveries', 2));
    }

    public function test_user_cannot_delete_another_users_delivery_or_a_shared_legacy_notification(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $otherMerchant = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'تاجر المالك الآخر',
            'username' => 'protected-inbox-merchant',
            'phone' => '07981000992',
            'password' => 'StrongPassword123!',
            'role' => 'merchant',
            'status' => 'active',
        ]);
        $campaign = $this->campaign(1);
        $otherDelivery = $this->delivery($campaign, $otherMerchant);
        $legacyShared = Notification::create([
            'tenant_id' => $merchant->tenant_id,
            'user_id' => null,
            'type' => 'announcement',
            'title_ar' => 'إشعار مشترك قديم',
            'body_ar' => 'لا يُحذف من صندوق مستخدم واحد.',
        ]);

        $this->actingAs($merchant)
            ->delete("/app/notifications/{$otherDelivery->id}")
            ->assertForbidden();
        $this->actingAs($merchant)
            ->delete("/app/notifications/{$legacyShared->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('notifications', ['id' => $otherDelivery->id]);
        $this->assertNotSoftDeleted('notifications', ['id' => $legacyShared->id]);
    }

    public function test_reading_one_delivery_or_all_personal_deliveries_never_changes_another_or_shared_row(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $otherMerchant = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'تاجر حالة قراءة آخر',
            'username' => 'read-state-other-merchant',
            'phone' => '07981000993',
            'password' => 'StrongPassword123!',
            'role' => 'merchant',
            'status' => 'active',
        ]);
        $campaign = $this->campaign(3);
        $mine = $this->delivery($campaign, $merchant);
        $mySecondDelivery = $this->delivery($campaign, $merchant);
        $other = $this->delivery($campaign, $otherMerchant);
        $legacyShared = Notification::create([
            'tenant_id' => $merchant->tenant_id,
            'user_id' => null,
            'type' => 'announcement',
            'title_ar' => 'إشعار مشترك للقراءة',
            'body_ar' => 'لا تتغير حالته من صندوق مستخدم واحد.',
        ]);

        $this->actingAs($merchant)
            ->from('/app/notifications')
            ->patch("/app/notifications/{$mine->id}/read")
            ->assertRedirect('/app/notifications');

        $this->assertDatabaseHas('notifications', ['id' => $mine->id]);
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
        $this->assertNull($legacyShared->fresh()->read_at);

        $this->actingAs($merchant)
            ->from('/app/notifications')
            ->post('/app/notifications/read-all')
            ->assertRedirect('/app/notifications');

        $this->assertNotNull($mySecondDelivery->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
        $this->assertNull($legacyShared->fresh()->read_at);

        $this->actingAs($merchant)
            ->patch("/app/notifications/{$other->id}/read")
            ->assertForbidden();
        $this->actingAs($merchant)
            ->patch("/app/notifications/{$legacyShared->id}/read")
            ->assertForbidden();
    }

    public function test_mobile_api_soft_deletes_only_the_authenticated_users_delivery(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $campaign = $this->campaign(1);
        $mine = $this->delivery($campaign, $merchant);

        Sanctum::actingAs($merchant);

        $this->deleteJson("/api/v1/notifications/{$mine->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('notifications', ['id' => $mine->id]);
        $this->assertDatabaseHas('notification_campaigns', ['id' => $campaign->id]);
    }

    private function campaign(int $recipientCount): NotificationCampaign
    {
        return NotificationCampaign::create([
            'audience' => 'merchants',
            'type' => 'announcement',
            'title_ar' => 'حملة اختبار صندوق المستخدم',
            'body_ar' => 'تبقى الحملة محفوظة عند حذف التسليم الشخصي.',
            'recipient_count' => $recipientCount,
            'sent_at' => now(),
        ]);
    }

    private function delivery(NotificationCampaign $campaign, User $user): Notification
    {
        return Notification::create([
            'campaign_id' => $campaign->id,
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => 'announcement',
            'title_ar' => 'إشعار اختبار',
            'body_ar' => 'هذا التسليم يخص مستخدمًا واحدًا.',
        ]);
    }
}
