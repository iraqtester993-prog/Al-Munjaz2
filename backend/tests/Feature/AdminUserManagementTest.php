<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\LoyaltyAccount;
use App\Models\Notification;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
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

    public function test_merchant_roster_lists_each_actual_merchant_account_and_its_review_data(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('role', 'merchant')->firstOrFail();
        $secondMerchant = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'تاجر إضافي',
            'username' => 'merchant-second',
            'phone' => '07919990111',
            'password' => 'Password123!',
            'role' => 'merchant',
            'status' => 'active',
            'shop_name' => 'متجر ثانٍ',
            'address' => 'بغداد — المنصور',
        ]);

        foreach (['id_front', 'id_back', 'residence', 'residence_back'] as $type) {
            Document::create([
                'user_id' => $secondMerchant->id,
                'type' => $type,
                'path' => "documents/test/merchant-{$type}.jpg",
                'status' => 'approved',
            ]);
        }

        $response = $this->actingAs($admin)
            ->get('/dashboard/merchants')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('role', 'merchant')
                ->has('rows', 2));

        $rows = $response->inertiaProps('rows');

        $this->assertTrue(collect($rows)->contains(fn (array $row) => (
            data_get($row, 'user.id') === $secondMerchant->id
            && data_get($row, 'user.shop_name') === 'متجر ثانٍ'
            && data_get($row, 'user.address') === 'بغداد — المنصور'
            && data_get($row, 'user.username') === 'merchant-second'
            && data_get($row, 'document_review.approved') === 4
            && data_get($row, 'document_review.missing') === 0
            && data_get($row, 'verification.ready_to_verify') === true
        )));
    }

    public function test_rosters_paginate_and_search_accounts_on_the_server(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('role', 'merchant')->firstOrFail();

        foreach (range(1, 27) as $index) {
            User::create([
                'tenant_id' => $merchant->tenant_id,
                'name' => "Merchant page {$index}",
                'username' => "merchant-page-{$index}",
                'phone' => sprintf('071%08d', $index),
                'password' => 'Password123!',
                'role' => 'merchant',
                'status' => $index === 27 ? 'pending' : 'active',
            ]);
        }

        $this->actingAs($admin)
            ->get('/dashboard/merchants?page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->has('rows', 4)
                ->where('pagination.currentPage', 2)
                ->where('pagination.lastPage', 2)
                ->where('pagination.total', 28));

        $this->actingAs($admin)
            ->get('/dashboard/merchants?search=merchant-page-27')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('query.search', 'merchant-page-27')
                ->has('rows', 1)
                ->where('rows.0.user.username', 'merchant-page-27'));
    }

    public function test_admin_can_correct_operational_account_data_without_changing_its_role(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $courier = User::where('role', 'courier')->firstOrFail();

        $this->actingAs($admin)
            ->put('/dashboard/users/'.$courier->id, [
                'name' => 'مندوب محدّث',
                'username' => 'courier-updated',
                'email' => 'courier.updated@example.test',
                'phone' => '07919990112',
                'shop_name' => 'must-not-be-used',
                'address' => 'بغداد — الكرادة',
                'vehicle' => 'sedan',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $courier->id,
            'name' => 'مندوب محدّث',
            'username' => 'courier-updated',
            'email' => 'courier.updated@example.test',
            'phone' => '07919990112',
            'address' => 'بغداد — الكرادة',
            'vehicle' => 'sedan',
            'role' => 'courier',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'user.profile_updated_by_admin',
            'subject_type' => User::class,
            'subject_id' => $courier->id,
        ]);
        $this->assertSame('courier', $courier->fresh()->role);
        $this->assertNull($courier->fresh()->shop_name);
        $this->assertSame(1, ActivityLog::query()->where('action', 'user.profile_updated_by_admin')->count());
    }

    public function test_suspending_one_operational_account_does_not_suspend_its_whole_company(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('role', 'merchant')->firstOrFail();
        $merchant->tenant->update(['status' => 'active']);

        $this->actingAs($admin)
            ->post('/dashboard/users/'.$merchant->id.'/status', ['status' => 'suspended'])
            ->assertRedirect();

        $this->assertSame('suspended', $merchant->fresh()->status);
        $this->assertSame('active', $merchant->tenant->fresh()->status);
    }

    public function test_courier_roster_exposes_full_profile_documents_and_internal_review_state(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $existingCourier = User::where('role', 'courier')->firstOrFail();
        $provinceId = $existingCourier->provinces()->value('provinces.id');

        $courier = User::create([
            'tenant_id' => $existingCourier->tenant_id,
            'name' => 'مندوب مراجعة المستمسكات',
            'username' => 'courier-document-review',
            'phone' => '07919990113',
            'password' => 'Password123!',
            'role' => 'courier',
            'status' => 'pending',
            'vehicle' => 'sedan',
            'address' => 'بغداد — الكرادة',
            'identity_number' => 'IQ-TEST-COURIER-001',
        ]);

        if ($provinceId) {
            $courier->provinces()->attach($provinceId, ['is_primary' => true]);
        }

        LoyaltyAccount::create(['user_id' => $courier->id, 'balance' => 125]);
        $residence = Document::create([
            'user_id' => $courier->id,
            'type' => 'residence',
            'path' => 'documents/test/courier-residence.jpg',
            'status' => 'approved',
        ]);
        $idFront = Document::create([
            'user_id' => $courier->id,
            'type' => 'id_front',
            'path' => 'documents/test/courier-id-front.jpg',
            'status' => 'pending',
        ]);
        $licenseFront = Document::create([
            'user_id' => $courier->id,
            'type' => 'license_front',
            'path' => 'documents/test/courier-license-front.jpg',
            'status' => 'rejected',
        ]);

        $response = $this->actingAs($admin)
            ->get('/dashboard/couriers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('role', 'courier'));

        $row = collect($response->inertiaProps('rows'))
            ->first(fn (array $candidate) => (int) $candidate['id'] === $courier->id);
        $seededCourierRow = collect($response->inertiaProps('rows'))
            ->first(fn (array $candidate) => (int) $candidate['id'] === $existingCourier->id);

        $this->assertNotNull($row);
        $this->assertNotNull($seededCourierRow);
        // The roster aggregates operational totals per role in grouped
        // queries; these values come from the fixed demo order set.
        $this->assertSame(5, data_get($seededCourierRow, 'assigned'));
        $this->assertSame(2, data_get($seededCourierRow, 'delivered'));
        $this->assertSame(1, data_get($seededCourierRow, 'returned'));
        $this->assertSame(2, data_get($seededCourierRow, 'in_progress'));
        $this->assertSame(95000, data_get($seededCourierRow, 'collected'));
        $this->assertSame($courier->id, data_get($row, 'user.id'));
        $this->assertSame('مندوب مراجعة المستمسكات', data_get($row, 'user.name'));
        $this->assertSame('07919990113', data_get($row, 'user.phone'));
        $this->assertSame('courier-document-review', data_get($row, 'user.username'));
        $this->assertSame('بغداد — الكرادة', data_get($row, 'user.address'));
        $this->assertSame('sedan', data_get($row, 'user.vehicle'));
        $this->assertSame(125, data_get($row, 'points_balance'));
        $this->assertSame('unsubmitted', data_get($row, 'document_review.status'));
        $this->assertSame(3, data_get($row, 'document_review.total'));
        $this->assertSame(1, data_get($row, 'document_review.approved'));
        $this->assertSame(1, data_get($row, 'document_review.pending'));
        $this->assertSame(1, data_get($row, 'document_review.rejected'));
        $this->assertSame(2, data_get($row, 'document_review.missing'));
        $this->assertFalse((bool) data_get($row, 'document_review.complete'));
        $this->assertContains($idFront->id, data_get($row, 'pendingDocs'));
        $this->assertTrue(collect(data_get($row, 'documents'))->contains('id', $residence->id));
        $this->assertTrue(collect(data_get($row, 'documents'))->contains('id', $licenseFront->id));

        // Courier document review intentionally stays distinct from the
        // public merchant verification badge.
        $this->assertFalse((bool) data_get($row, 'verification.verified'));
        $this->assertNull(data_get($row, 'verification.verified_at'));
    }

    public function test_admin_can_change_courier_status_without_mixing_it_with_document_review(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $existingCourier = User::where('role', 'courier')->firstOrFail();
        $courier = User::create([
            'tenant_id' => $existingCourier->tenant_id,
            'name' => 'مندوب حالة الحساب',
            'username' => 'courier-status-control',
            'phone' => '07919990114',
            'password' => 'Password123!',
            'role' => 'courier',
            'status' => 'pending',
        ]);

        // Registration is intentionally usable after OTP confirmation. The
        // dashboard can therefore activate or suspend an account separately
        // from its internal document-review workflow.
        $this->actingAs($admin)
            ->post("/dashboard/users/{$courier->id}/status", ['status' => 'active'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('status');

        $this->assertDatabaseHas('users', [
            'id' => $courier->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $courier->id,
            'type' => 'account',
            'title_en' => 'Account status',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'user.status',
            'subject_type' => 'user',
            'subject_id' => $courier->id,
        ]);

        $this->actingAs($admin)
            ->post("/dashboard/users/{$courier->id}/status", ['status' => 'suspended'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('status');

        $this->assertDatabaseHas('users', [
            'id' => $courier->id,
            'status' => 'suspended',
            'is_online' => false,
        ]);
    }

    public function test_reviewing_a_courier_document_is_audited_and_notifies_the_courier(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $courier = User::where('role', 'courier')->firstOrFail();
        $document = Document::create([
            'user_id' => $courier->id,
            'type' => 'id_front',
            'path' => 'documents/test/courier-audit-id-front.jpg',
            'status' => 'pending',
        ]);
        $merchant = User::where('role', 'merchant')->firstOrFail();
        $otherDocument = Document::create([
            'user_id' => $merchant->id,
            'type' => 'id_front',
            'path' => 'documents/test/merchant-audit-id-front.jpg',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post("/dashboard/users/{$courier->id}/documents/{$document->id}/review", ['status' => 'approved'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('status');

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $courier->tenant_id,
            'user_id' => $courier->id,
            'type' => 'account',
            'title_en' => 'Document review',
        ]);

        $activity = ActivityLog::query()
            ->where('action', 'user.document_reviewed')
            ->where('subject_type', Document::class)
            ->where('subject_id', $document->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $activity->user_id);
        $this->assertSame($courier->id, data_get($activity->data, 'account_id'));
        $this->assertSame('courier', data_get($activity->data, 'account_role'));
        $this->assertSame('id_front', data_get($activity->data, 'document_type'));
        $this->assertSame('approved', data_get($activity->data, 'status'));

        $this->actingAs($admin)
            ->post("/dashboard/users/{$courier->id}/documents/{$otherDocument->id}/review", ['status' => 'approved'])
            ->assertNotFound();

        $this->assertSame('pending', $otherDocument->fresh()->status);
        $this->assertSame(1, Notification::query()
            ->where('user_id', $courier->id)
            ->where('title_en', 'Document review')
            ->count());
    }
}
