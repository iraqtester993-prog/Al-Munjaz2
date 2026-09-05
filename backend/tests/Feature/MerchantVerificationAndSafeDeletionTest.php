<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MerchantVerificationAndSafeDeletionTest extends TestCase
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

    public function test_admin_can_only_grant_a_merchant_badge_after_all_submitted_documents_are_approved(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('role', 'merchant')->firstOrFail();

        $this->actingAs($admin)
            ->post("/dashboard/users/{$merchant->id}/merchant-verification", ['verified' => true])
            ->assertRedirect()
            ->assertSessionHasErrors('verification');

        $front = Document::create([
            'user_id' => $merchant->id,
            'type' => 'id_front',
            'path' => 'documents/test/merchant-id-front.jpg',
            'status' => 'approved',
        ]);
        Document::create([
            'user_id' => $merchant->id,
            'type' => 'id_back',
            'path' => 'documents/test/merchant-id-back.jpg',
            'status' => 'pending',
        ]);
        Document::create([
            'user_id' => $merchant->id,
            'type' => 'residence',
            'path' => 'documents/test/merchant-residence.jpg',
            'status' => 'approved',
        ]);
        Document::create([
            'user_id' => $merchant->id,
            'type' => 'residence_back',
            'path' => 'documents/test/merchant-residence-back.jpg',
            'status' => 'approved',
        ]);

        $this->actingAs($admin)
            ->post("/dashboard/users/{$merchant->id}/merchant-verification", ['verified' => true])
            ->assertRedirect()
            ->assertSessionHasErrors('verification');

        $this->actingAs($admin)
            ->post("/dashboard/users/{$merchant->id}/documents/{$front->id}/review", ['status' => 'rejected'])
            ->assertRedirect();

        $this->assertNull($merchant->fresh()->merchant_verified_at);

        $front->fresh()->update(['status' => 'approved']);
        $merchant->documents()->where('type', 'id_back')->update(['status' => 'approved']);

        $this->assertSame([
            'id_back' => 'approved',
            'id_front' => 'approved',
            'residence' => 'approved',
            'residence_back' => 'approved',
        ], Document::query()
            ->where('user_id', $merchant->id)
            ->orderBy('type')
            ->pluck('status', 'type')
            ->all());

        $this->actingAs($admin)
            ->post("/dashboard/users/{$merchant->id}/merchant-verification", ['verified' => true])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('verification');

        $merchant->refresh();
        $this->assertTrue($merchant->isMerchantVerified());
        $this->assertSame($admin->id, $merchant->merchant_verified_by);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'merchant.verification_granted',
            'subject_type' => User::class,
            'subject_id' => $merchant->id,
        ]);

        $this->actingAs($admin)
            ->post("/dashboard/users/{$merchant->id}/documents/{$front->id}/review", ['status' => 'rejected'])
            ->assertRedirect();

        $merchant->refresh();
        $this->assertFalse($merchant->isMerchantVerified());
        $this->assertNull($merchant->merchant_verified_by);
    }

    public function test_merchant_verification_rejects_a_document_bundle_above_the_safe_request_limit(): void
    {
        Storage::fake('public');
        config()->set('registration.merchant_verification_documents.max_file_kilobytes', 480);
        config()->set('registration.merchant_verification_documents.max_total_kilobytes', 1600);

        $merchant = User::where('role', 'merchant')->firstOrFail();
        $documentsBefore = Document::where('user_id', $merchant->id)->count();

        $this->actingAs($merchant)
            ->post('/profile/verification', [
                'name' => 'تاجر حجم كبير',
                'address' => 'بغداد — الكرادة',
                'phone' => '07800009876',
                'identity_number' => 'ID-UPLOAD-LIMIT',
                // Each file is valid individually, but the four-file request
                // must stay below the shared-hosting-safe aggregate limit.
                'id_front_document' => UploadedFile::fake()->create('id-front.pdf', 450, 'application/pdf'),
                'id_back_document' => UploadedFile::fake()->create('id-back.pdf', 450, 'application/pdf'),
                'residence_document' => UploadedFile::fake()->create('residence-front.pdf', 450, 'application/pdf'),
                'residence_back_document' => UploadedFile::fake()->create('residence-back.pdf', 450, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('documents');

        $this->assertSame($documentsBefore, Document::where('user_id', $merchant->id)->count());
    }

    public function test_admin_cannot_grant_public_merchant_verification_to_a_courier(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $courier = User::where('role', 'courier')->firstOrFail();

        $this->actingAs($admin)
            ->post("/dashboard/users/{$courier->id}/merchant-verification", ['verified' => true])
            ->assertNotFound();

        $this->assertNull($courier->fresh()->merchant_verified_at);
    }

    public function test_safe_delete_blocks_open_work_and_soft_deletes_a_settled_operational_account(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchantWithOpenOrders = User::where('role', 'merchant')->firstOrFail();

        $this->actingAs($admin)
            ->delete("/dashboard/users/{$merchantWithOpenOrders->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('users', [
            'id' => $merchantWithOpenOrders->id,
            'deleted_at' => null,
        ]);

        $settledMerchant = User::create([
            'tenant_id' => $merchantWithOpenOrders->tenant_id,
            'name' => 'تاجر بلا طلبات مفتوحة',
            'username' => 'merchant-safe-delete',
            'phone' => '07918880001',
            'password' => 'Password123!',
            'role' => 'merchant',
            'status' => 'active',
            'shop_name' => 'متجر الحذف الآمن',
            'address' => 'بغداد — الكرادة',
            'is_online' => true,
        ]);

        $this->actingAs($admin)
            ->delete("/dashboard/users/{$settledMerchant->id}")
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('delete');

        $this->assertSoftDeleted('users', ['id' => $settledMerchant->id]);
        $deleted = User::withTrashed()->findOrFail($settledMerchant->id);
        $this->assertSame('suspended', $deleted->status);
        $this->assertFalse((bool) $deleted->is_online);
        $this->assertSame(1, ActivityLog::query()
            ->where('action', 'user.soft_deleted')
            ->where('subject_id', $settledMerchant->id)
            ->count());
    }
}
