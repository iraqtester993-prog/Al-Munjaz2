<?php

namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InertiaTranslationPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        $this->withoutVite();
        $this->seed(PlanSeeder::class);
        $this->seed(ProvinceSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_regular_inertia_navigation_does_not_resend_the_full_translation_dictionary(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();

        $response = $this->actingAs($merchant)
            ->withHeaders($this->inertiaHeaders())
            ->get('/app');

        $response
            ->assertOk()
            ->assertJsonPath('component', 'Mobile/MerchantHome')
            ->assertJsonPath('props.translations', null);
    }

    public function test_switching_locale_refreshes_the_dictionary_once_then_returns_to_compact_navigation(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();

        $this->actingAs($merchant)
            ->post('/profile/locale', ['locale' => 'en'])
            ->assertRedirect();

        $firstNavigation = $this->actingAs($merchant)
            ->withHeaders($this->inertiaHeaders())
            ->get('/app');

        $firstNavigation
            ->assertOk()
            ->assertJsonPath('props.locale', 'en');
        $this->assertSame('Add New Order', $firstNavigation->json('props.translations.Add New Order'));

        $this->actingAs($merchant)
            ->withHeaders($this->inertiaHeaders())
            ->get('/app')
            ->assertOk()
            ->assertJsonPath('props.translations', null);
    }

    public function test_successful_login_refreshes_the_account_locale_dictionary_once(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $merchant->update(['locale' => 'en']);

        $this->post('/login', [
            'phone' => $merchant->phone,
            'password' => '123456',
            'role' => 'merchant',
        ])->assertRedirect('/app');

        $firstNavigation = $this
            ->withHeaders($this->inertiaHeaders())
            ->get('/app');

        $firstNavigation
            ->assertOk()
            ->assertJsonPath('props.locale', 'en');
        $this->assertSame('Add New Order', $firstNavigation->json('props.translations.Add New Order'));

        $this->withHeaders($this->inertiaHeaders())
            ->get('/app')
            ->assertOk()
            ->assertJsonPath('props.translations', null);
    }

    /** @return array<string, string> */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ];
    }
}
