<?php

namespace Tests\Feature;

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

class MerchantProfileVerificationUploadTest extends TestCase
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

    /**
     * A shared host can reject the multipart request before Laravel reaches
     * AppProfileController::verification().  This test exercises that exact
     * middleware path so the mobile UI receives a normal form error instead
     * of a generic 413/500 page when the four document files are too large
     * together.
     */
    public function test_an_oversized_merchant_verification_request_returns_a_document_form_error(): void
    {
        $merchant = User::where('role', 'merchant')->firstOrFail();

        $this->actingAs($merchant)
            ->from('/app/profile')
            ->withServerVariables([
                'CONTENT_LENGTH' => $this->postMaxSizeBytes() + 1,
            ])
            ->post('/profile/verification')
            ->assertRedirect('/app/profile')
            ->assertSessionHasErrors('documents');
    }

    public function test_merchant_verification_rejects_a_document_bundle_above_the_safe_hosting_limit(): void
    {
        Storage::fake('public');
        config()->set('registration.merchant_verification_documents', [
            'max_file_kilobytes' => 480,
            'max_total_kilobytes' => 1600,
            'target_image_kilobytes' => 300,
        ]);
        $merchant = User::where('role', 'merchant')->firstOrFail();
        $documentCountBeforeAttempt = Document::query()->where('user_id', $merchant->id)->count();

        $this->actingAs($merchant)
            ->from('/app/profile')
            ->post('/profile/verification', [
                'name' => $merchant->name,
                'address' => 'Baghdad — Karrada',
                'phone' => '07900007771',
                'identity_number' => 'MERCHANT-UPLOAD-LIMIT-1',
                'id_front_document' => UploadedFile::fake()->create('id-front.pdf', 450, 'application/pdf'),
                'id_back_document' => UploadedFile::fake()->create('id-back.pdf', 450, 'application/pdf'),
                'residence_document' => UploadedFile::fake()->create('residence-front.pdf', 450, 'application/pdf'),
                'residence_back_document' => UploadedFile::fake()->create('residence-back.pdf', 450, 'application/pdf'),
            ])
            ->assertRedirect('/app/profile')
            ->assertSessionHasErrors('documents');

        $this->assertSame($documentCountBeforeAttempt, Document::query()->where('user_id', $merchant->id)->count());
    }

    private function postMaxSizeBytes(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        if (is_numeric($value)) {
            return (int) $value;
        }

        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
