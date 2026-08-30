<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\MobileSlide;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileSlideLegacyFieldsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const LEGACY_FIELDS = [
        'tag_ar', 'tag_en', 'tag_ku',
        'cta_ar', 'cta_en', 'cta_ku',
        'action_url',
    ];

    public function test_platform_slider_create_and_update_ignore_legacy_tag_and_action_fields(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post('/dashboard/content', $this->slideInput([
                'tag_ar' => 'وسم يجب تجاهله',
                'cta_ar' => 'زر يجب تجاهله',
                'action_url' => 'https://example.test/ignored',
                // Also ignore names a stale client may use after the UI has
                // removed the fields.
                'cta_text' => 'legacy text',
                'cta_url' => 'https://example.test/legacy',
            ]))
            ->assertRedirect();

        $created = MobileSlide::query()->firstOrFail();
        foreach (self::LEGACY_FIELDS as $field) {
            $this->assertNull($created->getAttribute($field), "{$field} must not be stored for new slides.");
        }

        // Older rows retain their historical database values, but an update
        // must not let an old browser change them or return them to clients.
        $historical = $this->historicalSlide();
        $this->actingAs($admin)
            ->put('/dashboard/content/'.$historical->id, $this->slideInput([
                'title_ar' => 'عنوان تم تعديله',
                'tag_ar' => 'قيمة حديثة يجب تجاهلها',
                'cta_ar' => 'زر حديث يجب تجاهله',
                'action_url' => 'https://example.test/new-action',
            ]))
            ->assertRedirect();

        $historical->refresh();
        $this->assertSame('عنوان تم تعديله', $historical->title_ar);
        $this->assertSame('وسم تاريخي', $historical->tag_ar);
        $this->assertSame('زر تاريخي', $historical->cta_ar);
        $this->assertSame('/legacy-action', $historical->action_url);
    }

    public function test_public_and_dashboard_slider_payloads_do_not_expose_legacy_action_fields(): void
    {
        $slide = $this->historicalSlide();

        $this->assertPayloadHasNoLegacyFields($slide->mobilePayload());
        $this->assertPayloadHasNoLegacyFields($slide->dashboardPayload());

        $merchant = User::create([
            'name' => 'تاجر فحص السلايدر',
            'username' => 'slider-payload-merchant',
            'phone' => '07710009001',
            'password' => 'password',
            'role' => 'merchant',
            'status' => 'active',
        ]);

        $response = $this->actingAs($merchant)->get('/app')->assertOk();
        $heroSlide = $response->inertiaPage()['props']['heroSlides'][0];
        $this->assertPayloadHasNoLegacyFields($heroSlide);

        $admin = $this->superAdmin();
        $dashboardResponse = $this->actingAs($admin)->get('/dashboard/content')->assertOk();
        $dashboardSlide = $dashboardResponse->inertiaPage()['props']['slides'][0];
        $this->assertPayloadHasNoLegacyFields($dashboardSlide);
    }

    public function test_branch_slider_store_ignores_legacy_tag_and_action_fields(): void
    {
        $platform = Tenant::platform();
        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'code' => 'SLIDER-BRANCH',
            'name_ar' => 'فرع اختبار السلايدر',
            'city' => 'بغداد',
            'is_platform_managed' => true,
            'is_active' => true,
        ]);
        $manager = User::create([
            'tenant_id' => $platform->id,
            'branch_id' => $branch->id,
            'name' => 'مدير محتوى الفرع',
            'username' => 'branch-slider-manager',
            'phone' => '07710009002',
            'password' => 'password',
            'role' => 'branch_manager',
            'status' => 'active',
            'dashboard_permissions' => ['content'],
        ]);
        $branch->members()->attach($manager->id, ['access_role' => BranchMembership::MANAGER]);

        $this->actingAs($manager)
            ->post('/dashboard/branch/content', $this->slideInput([
                'branch_id' => $branch->id,
                'tag_ar' => 'وسم الفرع',
                'cta_ar' => 'زر الفرع',
                'action_url' => '/app/orders',
            ]))
            ->assertRedirect();

        $slide = MobileSlide::query()->where('branch_id', $branch->id)->firstOrFail();
        foreach (self::LEGACY_FIELDS as $field) {
            $this->assertNull($slide->getAttribute($field), "{$field} must not be stored by the branch content endpoint.");
        }

        $response = $this->actingAs($manager)->get('/dashboard/branch/content')->assertOk();
        $branchSlide = $response->inertiaPage()['props']['slides'][0];
        $this->assertPayloadHasNoLegacyFields($branchSlide);
    }

    private function superAdmin(): User
    {
        $admin = User::create([
            'name' => 'مدير السلايدر',
            'username' => 'slider-super-admin',
            'phone' => '07710009000',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin;
    }

    /** @param array<string, mixed> $overrides */
    private function slideInput(array $overrides = []): array
    {
        return array_replace([
            'audience' => 'merchant',
            'title_ar' => 'سلايدر للاختبار',
            'title_en' => 'Test slider',
            'body_ar' => 'نص تعريفي للسلايدر',
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides);
    }

    private function historicalSlide(): MobileSlide
    {
        $slide = MobileSlide::create($this->slideInput());
        $slide->forceFill([
            'tag_ar' => 'وسم تاريخي',
            'tag_en' => 'Historical tag',
            'tag_ku' => 'تاگی مێژوویی',
            'cta_ar' => 'زر تاريخي',
            'cta_en' => 'Historical action',
            'cta_ku' => 'کرداری مێژوویی',
            'action_url' => '/legacy-action',
        ])->save();

        return $slide->fresh();
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadHasNoLegacyFields(array $payload): void
    {
        foreach (self::LEGACY_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $payload, "{$field} must never leave the server in a slider payload.");
        }
    }
}
