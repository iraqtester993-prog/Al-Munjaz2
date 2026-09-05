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

class CourierProfileDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        $this->seed(PlanSeeder::class);
        $this->seed(ProvinceSeeder::class);
        $this->seed(DemoSeeder::class);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_courier_can_open_only_their_own_document(): void
    {
        $courier = User::query()->where('role', 'courier')->firstOrFail();
        $path = 'documents/'.$courier->id.'/id-front.jpg';
        Storage::disk('public')->put($path, 'image-content');

        $document = Document::create([
            'user_id' => $courier->id,
            'type' => 'id_front',
            'path' => $path,
            'status' => 'approved',
        ]);

        $this->actingAs($courier)
            ->get(route('profile.documents.show', $document))
            ->assertOk();

        $otherCourier = User::create([
            'tenant_id' => $courier->tenant_id,
            'name' => 'مندوب آخر',
            'username' => 'other-courier-documents',
            'phone' => '07919999998',
            'password' => 'Password123!',
            'role' => 'courier',
            'status' => 'active',
        ]);

        $this->actingAs($otherCourier)
            ->get(route('profile.documents.show', $document))
            ->assertNotFound();
    }

    public function test_courier_can_replace_an_allowed_document_and_it_returns_to_review(): void
    {
        $courier = User::query()->where('role', 'courier')->firstOrFail();
        $oldPath = 'documents/'.$courier->id.'/old-residence.jpg';
        Storage::disk('public')->put($oldPath, 'old-image');

        $document = Document::create([
            'user_id' => $courier->id,
            'type' => 'residence',
            'path' => $oldPath,
            'status' => 'approved',
        ]);

        $this->actingAs($courier)
            ->post(route('profile.documents.replace', $document), [
                'file' => UploadedFile::fake()->image('new-residence.jpg')->size(180),
            ])
            ->assertRedirect();

        $document->refresh();

        $this->assertSame('pending', $document->status);
        $this->assertNotSame($oldPath, $document->path);
        Storage::disk('public')->assertExists($document->path);
    }

    public function test_courier_can_update_personal_details_and_vehicle_from_the_profile_form(): void
    {
        $courier = User::query()->where('role', 'courier')->firstOrFail();

        $this->actingAs($courier)
            ->post(route('profile.update'), [
                'name' => 'مندوب محدّث',
                'phone' => $courier->phone,
                'address' => 'بغداد — الكرادة',
                'vehicle' => 'truck',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $courier->id,
            'name' => 'مندوب محدّث',
            'address' => 'بغداد — الكرادة',
            'vehicle' => 'truck',
        ]);
    }

    public function test_courier_cannot_save_an_account_phone_outside_077_or_078(): void
    {
        $courier = User::query()->where('role', 'courier')->firstOrFail();
        $originalPhone = $courier->phone;

        $this->actingAs($courier)
            ->post(route('profile.update'), [
                'name' => $courier->name,
                'phone' => '07912345678',
                'address' => $courier->address,
                'vehicle' => $courier->vehicle,
            ])
            ->assertSessionHasErrors('phone');

        $this->assertSame($originalPhone, $courier->fresh()->phone);
    }

    public function test_courier_cannot_replace_an_unrecognised_document_type(): void
    {
        $courier = User::query()->where('role', 'courier')->firstOrFail();
        $document = Document::create([
            'user_id' => $courier->id,
            'type' => 'passport',
            'path' => 'documents/'.$courier->id.'/legacy-license.jpg',
            'status' => 'approved',
        ]);

        $this->actingAs($courier)
            ->post(route('profile.documents.replace', $document), [
                'file' => UploadedFile::fake()->image('replacement.jpg')->size(180),
            ])
            ->assertNotFound();
    }
}
