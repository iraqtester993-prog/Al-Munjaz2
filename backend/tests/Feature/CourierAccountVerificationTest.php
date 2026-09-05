<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Order;
use App\Models\Province;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CourierOrderAccess;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourierAccountVerificationTest extends TestCase
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

    public function test_active_unverified_courier_cannot_view_or_claim_an_offer_until_the_dashboard_verifies_the_account(): void
    {
        [$admin, $merchant, $courier, $province] = $this->operationalActors();
        $order = $this->pendingOrder($merchant, $province, 'ALM-COURIER-VERIFY-GATE');

        // The availability policy is also the authorisation boundary for a
        // pending-order detail sheet, not merely a visual list filter.
        $this->assertFalse(app(CourierOrderAccess::class)
            ->available($courier)
            ->whereKey($order->id)
            ->exists());

        $this->actingAs($courier)
            ->getJson('/app/orders?filter=pending&list=1')
            ->assertOk()
            ->assertJsonMissing(['id' => $order->id]);

        $this->actingAs($courier)
            ->getJson("/app/orders?detail={$order->id}&pending=1")
            ->assertNotFound();

        // All normal operational prerequisites are deliberately satisfied;
        // this assertion proves the administrative verification itself is the
        // reason a newly registered courier cannot claim the work.
        $this->actingAs($courier)
            ->postJson(route('app.orders.claim', $order))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'courier_id' => null,
        ]);

        // A dashboard operator cannot bypass the mandatory-document review.
        $this->actingAs($admin)
            ->post("/dashboard/users/{$courier->id}/courier-verification", ['verified' => true])
            ->assertRedirect()
            ->assertSessionHasErrors('verification');

        $courier->refresh();
        $this->assertFalse($courier->isCourierVerified());
        $this->assertNull($courier->courier_verified_at);
        $this->assertNull($courier->courier_verified_by);

        $this->addApprovedCourierDocuments($courier);

        $this->actingAs($admin)
            ->post("/dashboard/users/{$courier->id}/courier-verification", ['verified' => true])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('verification');

        $courier->refresh();
        $this->assertTrue($courier->isCourierVerified());
        $this->assertNotNull($courier->courier_verified_at);
        $this->assertSame($admin->id, $courier->courier_verified_by);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'courier.verification_granted',
            'subject_type' => User::class,
            'subject_id' => $courier->id,
        ]);

        $this->assertTrue(app(CourierOrderAccess::class)
            ->available($courier)
            ->whereKey($order->id)
            ->exists());

        $this->actingAs($courier)
            ->getJson('/app/orders?filter=pending&list=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id]);

        $this->actingAs($courier)
            ->post(route('app.orders.claim', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame($courier->id, $order->courier_id);
    }

    public function test_rejecting_a_reviewed_courier_document_revokes_new_order_permission_and_duty_status(): void
    {
        [$admin, , $courier] = $this->operationalActors(verified: true);
        $document = Document::create([
            'user_id' => $courier->id,
            'type' => 'id_front',
            'path' => 'documents/test/courier-verified-id-front.jpg',
            'status' => 'approved',
        ]);

        $this->actingAs($admin)
            ->post("/dashboard/users/{$courier->id}/documents/{$document->id}/review", ['status' => 'rejected'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('status');

        $courier->refresh();
        $this->assertFalse($courier->isCourierVerified());
        $this->assertFalse((bool) $courier->is_online);
        $this->assertNull($courier->courier_verified_at);
        $this->assertNull($courier->courier_verified_by);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'courier.verification_revoked',
            'subject_type' => User::class,
            'subject_id' => $courier->id,
        ]);
    }

    public function test_newly_verified_courier_can_view_and_claim_an_unassigned_offer_published_before_registration(): void
    {
        [$admin, $merchant, $courier, $province] = $this->operationalActors();
        $registeredAt = now()->subHour();
        $publishedAt = $registeredAt->copy()->subDay();
        $order = $this->pendingOrder($merchant, $province, 'ALM-COURIER-PRE-REGISTRATION-OFFER');
        $order->forceFill([
            'offer_opened_at' => $publishedAt,
            'pickup_deadline_at' => $registeredAt->copy()->subMinute(),
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ])->save();
        $courier->forceFill([
            'created_at' => $registeredAt,
            'updated_at' => $registeredAt,
        ])->save();

        $this->assertFalse(app(CourierOrderAccess::class)
            ->available($courier)
            ->whereKey($order->id)
            ->exists());

        $this->addApprovedCourierDocuments($courier);
        $this->actingAs($admin)
            ->post("/dashboard/users/{$courier->id}/courier-verification", ['verified' => true])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('verification');

        $courier->refresh();
        $this->assertTrue(app(CourierOrderAccess::class)
            ->available($courier)
            ->whereKey($order->id)
            ->exists());

        $this->actingAs($courier)
            ->getJson('/app/orders?filter=pending&list=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id]);

        $this->actingAs($courier)
            ->post(route('app.orders.claim', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($courier->id, $order->fresh()->courier_id);
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_replacing_a_verified_courier_document_revokes_new_order_permission_until_it_is_reviewed_again(): void
    {
        Storage::fake('public');

        [, , $courier] = $this->operationalActors(verified: true);
        $oldPath = "documents/{$courier->id}/verified-residence.jpg";
        Storage::disk('public')->put($oldPath, 'old-image');
        $document = Document::create([
            'user_id' => $courier->id,
            'type' => 'residence',
            'path' => $oldPath,
            'status' => 'approved',
        ]);

        $this->actingAs($courier)
            ->post(route('profile.documents.replace', $document), [
                'file' => UploadedFile::fake()->image('replacement-residence.jpg')->size(180),
            ])
            ->assertRedirect();

        $courier->refresh();
        $document->refresh();
        $this->assertSame('pending', $document->status);
        $this->assertFalse($courier->isCourierVerified());
        $this->assertFalse((bool) $courier->is_online);
        $this->assertNull($courier->courier_verified_at);
        $this->assertNull($courier->courier_verified_by);
    }

    /** @return array{0: User, 1: User, 2: User, 3: Province} */
    private function operationalActors(bool $verified = false): array
    {
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $merchant = User::query()->where('role', 'merchant')->firstOrFail();
        $prototypeCourier = User::query()->where('role', 'courier')->firstOrFail();
        $province = $merchant->provinces()->active()->firstOrFail();

        $courier = User::create([
            'tenant_id' => $prototypeCourier->tenant_id,
            'name' => 'مندوب توثيق تشغيلي',
            'username' => 'courier-operational-verification',
            'phone' => '07719990155',
            'password' => 'Password123!',
            'role' => 'courier',
            'status' => 'active',
            'vehicle' => 'bike',
            'address' => 'بغداد — الكرادة',
            'courier_verified' => $verified,
            'courier_verified_at' => $verified ? now() : null,
            'courier_verified_by' => $verified ? $admin->id : null,
            'is_online' => true,
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);

        $courier->provinces()->attach($province->id, ['is_primary' => true]);
        Wallet::create([
            'user_id' => $courier->id,
            'balance' => 20_000,
            'budget' => 50_000,
            'budget_balance' => 50_000,
        ]);

        return [$admin, $merchant, $courier->fresh(), $province];
    }

    private function addApprovedCourierDocuments(User $courier): void
    {
        foreach (['residence', 'id_front', 'id_back', 'license_front', 'license_back'] as $type) {
            Document::create([
                'user_id' => $courier->id,
                'type' => $type,
                'path' => "documents/test/{$courier->id}-{$type}.jpg",
                'status' => 'approved',
            ]);
        }
    }

    private function pendingOrder(User $merchant, Province $province, string $trackNo): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل توثيق المندوب',
            'customer_name_en' => 'Courier verification customer',
            'phone' => '07710000991',
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => 'متجر اختبار التوثيق',
            'delivery_vehicle' => 'normal',
            'price' => 25_000,
            'fee' => 3_000,
            'return_fee' => 3_000,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'pickup_deadline_at' => now()->addMinutes(30),
            'date' => today(),
        ]);
    }
}
