<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Province;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MobileOrderPaginationTest extends TestCase
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

    public function test_mobile_order_list_is_cursor_paginated_and_keeps_full_detail_on_demand(): void
    {
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();

        // Create enough records to prove that a list response stays bounded
        // instead of serialising an account's complete order history.
        foreach (range(1, 24) as $number) {
            $this->makePendingOrder($merchant, $province, $number);
        }

        $response = $this->actingAs($merchant)
            ->get('/app/orders?filter=pending');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Orders')
                ->has('orders', 10)
                ->where('pagination.has_more', true)
                ->where('pagination.per_page', 10)
                ->missing('orders.0.timeline')
                ->missing('orders.0.merchant')
                // Customer phone is intentionally available in every order state;
                // the expensive order relations remain detail-only.
                ->has('orders.0.phone'));

        $firstPage = $response->viewData('page')['props'];
        $cursor = $firstPage['pagination']['next_cursor'] ?? null;

        $this->assertNotEmpty($cursor);

        $nextPage = $this->actingAs($merchant)
            ->getJson('/app/orders?filter=pending&list=1&cursor='.urlencode($cursor));

        $nextPage
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 10);

        $this->assertNotEmpty($nextPage->json('orders'));
        $this->assertLessThanOrEqual(10, count($nextPage->json('orders')));

        $firstPageIds = collect($firstPage['orders'])->pluck('id')->all();
        $nextPageIds = collect($nextPage->json('orders'))->pluck('id')->all();

        $this->assertEmpty(array_intersect($firstPageIds, $nextPageIds));

        $detailId = $firstPage['orders'][0]['id'];

        $this->actingAs($merchant)
            ->getJson("/app/orders?detail={$detailId}")
            ->assertOk()
            ->assertJsonPath('order.id', $detailId)
            ->assertJsonPath('order.merchant.name', $merchant->name)
            ->assertJsonStructure([
                'order' => [
                    'phone',
                    'phone2',
                    'timeline',
                    'origin_branch',
                    'destination_branch',
                ],
            ]);
    }

    private function makePendingOrder(User $merchant, Province $province, int $number): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => "ALM-PAGE-{$number}",
            'source' => 'merchant',
            'customer_name_ar' => "عميل قائمة {$number}",
            'customer_name_en' => "Cursor customer {$number}",
            'phone' => '078'.str_pad((string) $number, 8, '0', STR_PAD_LEFT),
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => 20_000 + $number,
            'fee' => 2_500,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'date' => today(),
            'pickup_deadline_at' => now()->addMinutes(30),
        ]);
    }
}
