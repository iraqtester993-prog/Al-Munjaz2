<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationalProvinceRegistrationTest extends TestCase
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

    public function test_registration_is_explicitly_unavailable_without_an_operating_governorate(): void
    {
        Branch::withoutGlobalScope(TenantScope::class)
            ->where('is_platform_managed', true)
            ->update(['is_active' => false]);

        $this->get('/register/merchant')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Register')
                ->where('registrationAvailable', false)
                ->has('provinces', 0));

        $province = Province::query()->firstOrFail();
        $this->post('/register', [
            'role' => 'merchant',
            'name' => 'تاجر بلا فرع',
            'shop' => 'متجر بلا فرع',
            'address' => 'بغداد',
            'province_id' => $province->id,
            'phone' => '07700004567',
            'password' => 'temporary-pass-123',
            'password_confirmation' => 'temporary-pass-123',
        ])->assertSessionHasErrors('province_id');

        $this->assertDatabaseMissing('users', ['phone' => '07700004567']);
    }

    public function test_registration_requires_an_eleven_digit_077_or_078_phone_number(): void
    {
        $province = Province::query()->whereNull('tenant_id')->firstOrFail();

        foreach (['0771234567', '077123456789', '07912345678', '07712A45678'] as $phone) {
            $this->post('/register', [
                'role' => 'merchant',
                'name' => 'تاجر رقم هاتف',
                'shop' => 'متجر رقم هاتف',
                'address' => 'بغداد',
                'province_id' => $province->id,
                'phone' => $phone,
                'password' => 'temporary-pass-123',
                'password_confirmation' => 'temporary-pass-123',
            ])->assertSessionHasErrors('phone');

            $this->assertDatabaseMissing('users', ['phone' => $phone]);
        }

        foreach (['07712345678', '07812345678'] as $phone) {
            $this->post('/register', [
                'role' => 'merchant',
                'name' => 'تاجر رقم هاتف '.$phone,
                'shop' => 'متجر رقم هاتف '.$phone,
                'address' => 'بغداد',
                'province_id' => $province->id,
                'phone' => $phone,
                'password' => 'temporary-pass-123',
                'password_confirmation' => 'temporary-pass-123',
            ])->assertRedirect('/verify-otp');

            $this->assertDatabaseHas('users', ['phone' => $phone]);
        }
    }
}
