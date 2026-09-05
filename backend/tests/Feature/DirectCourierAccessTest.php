<?php

namespace Tests\Feature;

use App\Models\FinanceRequest;
use App\Models\User;
use App\Services\CourierLocationService;
use App\Services\FinanceRequestService;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DirectCourierAccessTest extends TestCase
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

    public function test_retired_operational_roles_cannot_start_or_use_a_mobile_api_session(): void
    {
        // The API login route is independently covered here; the named
        // production throttle is not registered in the in-memory test app.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ($this->retiredOperationalUsers() as $legacyUser) {
            $this->post('/login', [
                'phone' => $legacyUser->phone,
                'password' => 'Password123!',
                'role' => 'courier',
            ])->assertSessionHasErrors('phone');

            $this->assertGuest();

            $this->postJson('/api/v1/auth/login', [
                'username' => $legacyUser->username,
                'password' => 'Password123!',
                'device_name' => 'retired-role-regression',
            ])->assertForbidden();

            Sanctum::actingAs($legacyUser);

            $this->getJson('/api/v1/me')->assertForbidden();
            $this->getJson('/api/v1/wallet')->assertForbidden();

            $this->app['auth']->forgetGuards();
        }
    }

    public function test_retired_operational_roles_cannot_mutate_wallets_or_share_locations(): void
    {
        $finance = app(FinanceRequestService::class);
        $locations = app(CourierLocationService::class);

        foreach ($this->retiredOperationalUsers() as $legacyUser) {
            $wallet = $legacyUser->wallet()->create([
                'balance' => 5000,
                'budget' => 10000,
                'budget_balance' => 10000,
            ]);

            $this->actingAs($legacyUser)
                ->post('/app/wallet/handover', ['amount' => 1000])
                ->assertForbidden();
            $this->actingAs($legacyUser)
                ->post('/app/wallet/recharge', ['amount' => 1000, 'qi_reference' => 'QI-'.$legacyUser->id])
                ->assertForbidden();
            $this->actingAs($legacyUser)
                ->post('/app/wallet/budget', ['amount' => 1000])
                ->assertForbidden();
            $this->actingAs($legacyUser)
                ->post('/app/wallet/budget/reduce', ['amount' => 1000])
                ->assertForbidden();
            $this->actingAs($legacyUser)
                ->postJson('/app/location', ['latitude' => 33.3152, 'longitude' => 44.3661])
                ->assertForbidden();

            Sanctum::actingAs($legacyUser);
            $this->postJson('/api/v1/courier/location', ['latitude' => 33.3152, 'longitude' => 44.3661])
                ->assertForbidden();

            foreach ([
                FinanceRequest::CASH_HANDOVER,
                FinanceRequest::BUDGET_RECHARGE,
                FinanceRequest::QI_TOPUP,
            ] as $type) {
                try {
                    $finance->submit($legacyUser, $type, 1000, null, null, $type === FinanceRequest::QI_TOPUP ? 'QI-'.$legacyUser->id : null);
                    $this->fail("Expected {$legacyUser->role} to be rejected for {$type}.");
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('finance', $exception->errors());
                }
            }

            try {
                $finance->declareCourierBudget($legacyUser, 1000);
                $this->fail("Expected {$legacyUser->role} to be rejected from a budget declaration.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('finance', $exception->errors());
            }

            try {
                $finance->reduceCourierBudget($legacyUser, 1000);
                $this->fail("Expected {$legacyUser->role} to be rejected from a budget reduction.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('finance', $exception->errors());
            }

            try {
                $locations->record($legacyUser, ['latitude' => 33.3152, 'longitude' => 44.3661]);
                $this->fail("Expected {$legacyUser->role} to be rejected from location storage.");
            } catch (AuthorizationException) {
                // The service is intentionally protected even when called
                // outside the HTTP controller.
            }

            $this->assertSame(10000, (int) $wallet->fresh()->budget);
            $this->assertSame(10000, (int) $wallet->fresh()->budget_balance);
            $this->assertNull($legacyUser->fresh()->current_latitude);

            $this->app['auth']->forgetGuards();
        }
    }

    public function test_direct_courier_keeps_wallet_and_location_access(): void
    {
        $courier = User::query()->where('username', 'مندوب')->firstOrFail();
        $wallet = $courier->wallet()->firstOrFail();
        $budgetBefore = (int) $wallet->budget;
        $availableBudgetBefore = (int) $wallet->budget_balance;

        $this->actingAs($courier)
            ->post('/app/wallet/budget', ['amount' => 1000, 'note' => 'اختبار وصول المندوب'])
            ->assertRedirect();

        $this->assertSame($budgetBefore + 1000, (int) $wallet->fresh()->budget);
        $this->assertSame($availableBudgetBefore + 1000, (int) $wallet->fresh()->budget_balance);

        $this->actingAs($courier)
            ->post('/app/wallet/budget/reduce', ['amount' => 1000, 'note' => 'اختبار تخفيض الميزانية'])
            ->assertRedirect();
        $this->assertSame($budgetBefore, (int) $wallet->fresh()->budget);
        $this->assertSame($availableBudgetBefore, (int) $wallet->fresh()->budget_balance);

        $this->actingAs($courier)
            ->postJson('/app/location', ['latitude' => 33.3152, 'longitude' => 44.3661])
            ->assertOk();

        Sanctum::actingAs($courier);
        $this->getJson('/api/v1/me')->assertOk();
        $this->postJson('/api/v1/courier/location', ['latitude' => 33.3153, 'longitude' => 44.3662])
            ->assertOk();
    }

    public function test_live_location_rows_exclude_retired_operational_accounts(): void
    {
        $courier = User::query()->where('username', 'مندوب')->firstOrFail();
        $legacyUser = $this->retiredOperationalUsers()->first();

        $courier->update([
            'current_latitude' => 33.3152,
            'current_longitude' => 44.3661,
            'location_updated_at' => now(),
        ]);
        $legacyUser->update([
            'current_latitude' => 33.3153,
            'current_longitude' => 44.3662,
            'location_updated_at' => now(),
        ]);

        $locationIds = collect(app(CourierLocationService::class)->dashboardRows())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($courier->id, $locationIds);
        $this->assertNotContains($legacyUser->id, $locationIds);
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function retiredOperationalUsers()
    {
        $courier = User::query()->where('username', 'مندوب')->firstOrFail();

        return collect(['pickup_courier', 'delivery_courier', 'transporter'])
            ->map(function (string $role, int $index) use ($courier): User {
                return User::create([
                    'tenant_id' => $courier->tenant_id,
                    'name' => 'دور تشغيلي قديم '.$role,
                    'username' => 'legacy-'.$role,
                    'email' => 'legacy-'.$role.'@example.test',
                    'phone' => '079910000'.($index + 1),
                    'password' => 'Password123!',
                    'role' => $role,
                    'status' => 'active',
                ]);
            });
    }
}
