<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\MobileSlide;
use App\Models\User;
use App\Services\LoyaltyPointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicContentSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_public_content_and_legal_pages_receive_it(): void
    {
        $admin = User::create([
            'name' => 'منصة الإدارة',
            'username' => 'public-content-admin',
            'phone' => '07700001111',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'brand_name' => 'المنجز السريع',
            'brand_tagline' => 'منصة توصيل',
            'support_phone' => '07700000000',
            'support_email' => 'support@example.test',
            'currency' => 'IQD',
            'delivery_fee' => 3500,
            'order_expiry_minutes' => 30,
            'pickup_eta_minutes' => 20,
            'public_content' => [
                'about_app' => ['ar' => 'وصف مخصص للتطبيق'],
                'developer_name' => ['ar' => 'شركة عراق تكنو'],
                'developer_description' => ['ar' => 'وصف مخصص للشركة المطورة'],
                'privacy_policy' => ['ar' => "سياسة مخصصة.\n\nفقرة ثانية."],
                'terms_of_use' => ['ar' => 'شروط مخصصة للاستخدام.'],
            ],
        ])->assertRedirect();

        $content = Setting::publicContent();
        $this->assertSame('وصف مخصص للتطبيق', $content['about_app']['ar']);
        $this->assertSame('شركة عراق تكنو', $content['developer_name']['ar']);
        $this->assertSame('', $content['privacy_policy']['en']);

        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Legal')
                ->where('legalContent.privacy_policy.ar', "سياسة مخصصة.\n\nفقرة ثانية.")
                ->where('developer.developer_name.ar', 'شركة عراق تكنو')
            );
    }

    public function test_settings_page_exposes_the_slider_summary_and_courier_point_rule(): void
    {
        $admin = User::create([
            'name' => 'إدارة الإعدادات',
            'username' => 'settings-tabs-admin',
            'phone' => '07700002222',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $admin->forceFill(['is_super_admin' => true])->save();

        Setting::set(LoyaltyPointService::POINTS_PER_DELIVERY_KEY, 18);
        MobileSlide::create([
            'audience' => 'courier',
            'title_ar' => 'سلايدر المندوبين',
            'is_active' => true,
            'sort_order' => 4,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings')
                ->where('settings.points_per_delivery', 18)
                ->has('slides', 1)
                ->where('slides.0.title_ar', 'سلايدر المندوبين')
                ->where('slides.0.audience', 'courier')
            );
    }

    public function test_admin_can_save_the_courier_point_rule_from_the_settings_tab(): void
    {
        $admin = User::create([
            'name' => 'إدارة النقاط',
            'username' => 'settings-points-admin',
            'phone' => '07700003333',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($admin)
            ->post(route('admin.loyalty.settings'), ['points_per_delivery' => 24])
            ->assertRedirect();

        $this->assertSame(24, (int) Setting::get(LoyaltyPointService::POINTS_PER_DELIVERY_KEY));
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'loyalty.delivery_reward_updated',
            'user_id' => $admin->id,
        ]);
    }
}
