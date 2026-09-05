<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Province;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CourierOrderAssignmentService;
use App\Services\OrderPickupRecoveryService;
use App\Services\OrderWorkflowService;
use App\Services\PricingService;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialIntegrityTest extends TestCase
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

    public function test_a_delivery_reserves_only_product_value_and_keeps_the_fixed_claim_deduction(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $startingBalance = (int) $merchant->wallet->balance;
        $startingCourierBalance = (int) $courier->wallet->balance;
        $startingCourierBudget = (int) $courier->wallet->budget;
        $startingCourierBudgetBalance = (int) $courier->wallet->budget_balance;
        $adminDeduction = 2000;
        $courier->update(['admin_deduction_per_order' => $adminDeduction]);

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-SETTLEMENT-001',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل تحصيل',
            'customer_name_en' => 'Collection customer',
            'phone' => '07700000001',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'delivery_vehicle' => 'normal',
            'price' => 30000,
            'fee' => 4500,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'date' => today(),
        ]);

        // Accepting a job reserves product value from the available budget
        // and charges the courier's immutable administration deduction. The
        // delivery fee is not part of the budget reservation.
        app(CourierOrderAssignmentService::class)->assign($order, $courier, $courier, 'اختبار أجرة التوصيل');
        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame($adminDeduction, (int) $order->admin_deduction_applied);
        $this->assertSame($startingCourierBudget, (int) $courier->wallet->fresh()->budget);
        $this->assertSame($startingCourierBudgetBalance - 30000, (int) $courier->wallet->fresh()->budget_balance);
        $this->assertSame($startingCourierBalance - $adminDeduction, (int) $courier->wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'paid_order',
            'amount' => 30000,
            'direction' => -1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'commission',
            'amount' => $adminDeduction,
            'direction' => -1,
        ]);

        app(OrderWorkflowService::class)->changeStatus($order, 'courier', $courier);
        app(OrderWorkflowService::class)->changeStatus($order, 'delivered', $courier);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame($startingBalance + 30000, (int) $merchant->wallet->fresh()->balance);
        $this->assertSame($startingCourierBudget, (int) $courier->wallet->fresh()->budget);
        $this->assertSame($startingCourierBudgetBalance, (int) $courier->wallet->fresh()->budget_balance);
        $this->assertSame($startingCourierBalance - $adminDeduction, (int) $courier->wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'collected',
            'amount' => 2500,
            'direction' => 1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $merchant->id,
            'type' => 'settlement',
            'amount' => 30000,
            'direction' => 1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'commission',
            'amount' => $adminDeduction,
            'direction' => -1,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'delivery_fee',
        ]);
        $this->assertSame(
            $startingBalance + 30000,
            (int) Tenant::findOrFail($merchant->tenant_id)->wallet_balance,
        );

        // A duplicate status submission must remain a no-op financially.
        app(OrderWorkflowService::class)->changeStatus($order, 'delivered', $courier);
        $this->assertSame(1, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('user_id', $merchant->id)
            ->where('type', 'settlement')
            ->count());
        $this->assertSame($startingBalance + 30000, (int) $merchant->wallet->fresh()->balance);
        $this->assertSame($startingCourierBalance - $adminDeduction, (int) $courier->wallet->fresh()->balance);
    }

    public function test_claim_uses_the_global_deduction_when_the_courier_has_no_personal_override(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $courier->update(['admin_deduction_per_order' => 0]);
        Setting::set('admin_deduction_fee', 2000);
        $startingBalance = (int) $courier->wallet->balance;
        $startingBudget = (int) $courier->wallet->budget;
        $startingBudgetBalance = (int) $courier->wallet->budget_balance;

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-GLOBAL-DEDUCTION-001',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل الاستقطاع العام',
            'customer_name_en' => 'Global deduction customer',
            'phone' => '07700000002',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'delivery_vehicle' => 'normal',
            'price' => 24000,
            'fee' => 5000,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'date' => today(),
        ]);

        app(CourierOrderAssignmentService::class)->assign($order, $courier, $courier, 'اختبار الاستقطاع الافتراضي');

        $this->assertSame(2000, (int) $order->fresh()->admin_deduction_applied);
        $this->assertSame($startingBudget, (int) $courier->wallet->fresh()->budget);
        $this->assertSame($startingBudgetBalance - 24000, (int) $courier->wallet->fresh()->budget_balance);
        $this->assertSame($startingBalance - 2000, (int) $courier->wallet->fresh()->balance);
    }

    public function test_claim_is_rejected_when_either_available_budget_or_qi_balance_is_insufficient(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $courier->update(['admin_deduction_per_order' => 0]);
        Setting::set('admin_deduction_fee', 2000);
        $wallet = $courier->wallet()->firstOrFail();
        $originalBalance = (int) $wallet->balance;
        $originalBudget = (int) $wallet->budget;
        $originalBudgetBalance = (int) $wallet->budget_balance;

        $budgetBlocked = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-BUDGET-BLOCKED-001',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل رصيد ميزانية غير كاف',
            'customer_name_en' => 'Budget blocked customer',
            'phone' => '07700000003',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'fee' => 5000,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'date' => today(),
        ]);
        $wallet->update(['budget_balance' => 24999]);

        try {
            app(CourierOrderAssignmentService::class)->assign($budgetBlocked, $courier, $courier, 'اختبار رصيد الميزانية');
            $this->fail('Expected an insufficient budget validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertSame('pending', $budgetBlocked->fresh()->status);
        $this->assertSame($originalBalance, (int) $wallet->fresh()->balance);
        $this->assertSame(24999, (int) $wallet->fresh()->budget_balance);
        $this->assertDatabaseMissing('transactions', ['order_id' => $budgetBlocked->id]);

        $qiBlocked = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-QI-BLOCKED-001',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل رصيد Qi غير كاف',
            'customer_name_en' => 'Qi blocked customer',
            'phone' => '07700000004',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'fee' => 5000,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'date' => today(),
        ]);
        $wallet->update(['budget_balance' => $originalBudgetBalance, 'balance' => 1999]);

        try {
            app(CourierOrderAssignmentService::class)->assign($qiBlocked, $courier, $courier, 'اختبار رصيد Qi');
            $this->fail('Expected an insufficient Qi balance validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertSame('pending', $qiBlocked->fresh()->status);
        $this->assertSame($originalBudget, (int) $wallet->fresh()->budget);
        $this->assertSame($originalBudgetBalance, (int) $wallet->fresh()->budget_balance);
        $this->assertSame(1999, (int) $wallet->fresh()->balance);
        $this->assertDatabaseMissing('transactions', ['order_id' => $qiBlocked->id]);
    }

    public function test_reoffering_an_unpicked_order_restores_both_available_budget_and_claim_deduction(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $courier->update(['admin_deduction_per_order' => 2000]);
        $startingBudget = (int) $courier->wallet->budget;
        $startingBudgetBalance = (int) $courier->wallet->budget_balance;
        $startingBalance = (int) $courier->wallet->balance;

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-REOFFER-REFUND-001',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل إعادة طرح',
            'customer_name_en' => 'Reoffer customer',
            'phone' => '07700000005',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'delivery_vehicle' => 'normal',
            'price' => 26000,
            'fee' => 5000,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'date' => today(),
        ]);

        app(CourierOrderAssignmentService::class)->assign($order, $courier, $courier, 'اختبار إعادة طرح');
        $order->refresh()->forceFill(['pickup_deadline_at' => now()->subMinute()])->save();

        app(OrderPickupRecoveryService::class)->reoffer($order, $admin, 'لم يصل المندوب إلى التاجر.');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->courier_id);
        $this->assertNull($order->fresh()->admin_deduction_applied);
        $this->assertSame($startingBudget, (int) $courier->wallet->fresh()->budget);
        $this->assertSame($startingBudgetBalance, (int) $courier->wallet->fresh()->budget_balance);
        $this->assertSame($startingBalance, (int) $courier->wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'budget_release',
            'amount' => 26000,
            'direction' => 1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'commission_refund',
            'amount' => 2000,
            'direction' => 1,
        ]);
    }

    public function test_terminal_delivery_releases_the_active_second_courier_hold_after_reoffer(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $firstCourier = User::where('username', 'مندوب')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $secondCourier = $this->makeSecondCourier($firstCourier, $province->id);
        $price = 26000;
        $firstBudgetBefore = (int) $firstCourier->wallet->budget_balance;
        $secondBudgetBefore = (int) $secondCourier->wallet->budget_balance;

        $order = $this->makePendingOrder($merchant, $province->id, 'ALM-REOFFER-SECOND-DELIVERY', $price);

        app(CourierOrderAssignmentService::class)->assign($order, $firstCourier, $firstCourier, 'القبول الأول');
        $order->refresh()->forceFill(['pickup_deadline_at' => now()->subMinute()])->save();
        app(OrderPickupRecoveryService::class)->reoffer($order, $admin, 'إعادة طرح بعد انتهاء الوصول الأول.');

        app(CourierOrderAssignmentService::class)->assign($order, $secondCourier, $secondCourier, 'القبول الثاني');
        $this->assertSame($secondBudgetBefore - $price, (int) $secondCourier->wallet->fresh()->budget_balance);

        app(OrderWorkflowService::class)->changeStatus($order, 'courier', $secondCourier);
        app(OrderWorkflowService::class)->changeStatus($order, 'delivered', $secondCourier);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame($firstBudgetBefore, (int) $firstCourier->wallet->fresh()->budget_balance);
        $this->assertSame($secondBudgetBefore, (int) $secondCourier->wallet->fresh()->budget_balance);
        $this->assertSame(1, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('user_id', $firstCourier->id)
            ->where('type', 'budget_release')
            ->count());
        $this->assertSame(1, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('user_id', $secondCourier->id)
            ->where('type', 'budget_release')
            ->where('amount', $price)
            ->count());
    }

    public function test_reoffering_again_releases_the_active_second_courier_hold(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $firstCourier = User::where('username', 'مندوب')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $secondCourier = $this->makeSecondCourier($firstCourier, $province->id);
        $price = 27000;
        $firstBudgetBefore = (int) $firstCourier->wallet->budget_balance;
        $secondBudgetBefore = (int) $secondCourier->wallet->budget_balance;

        $order = $this->makePendingOrder($merchant, $province->id, 'ALM-REOFFER-SECOND-REOFFER', $price);

        app(CourierOrderAssignmentService::class)->assign($order, $firstCourier, $firstCourier, 'القبول الأول');
        $order->refresh()->forceFill(['pickup_deadline_at' => now()->subMinute()])->save();
        app(OrderPickupRecoveryService::class)->reoffer($order, $admin, 'إعادة طرح بعد انتهاء الوصول الأول.');

        app(CourierOrderAssignmentService::class)->assign($order, $secondCourier, $secondCourier, 'القبول الثاني');
        $order->refresh()->forceFill(['pickup_deadline_at' => now()->subMinute()])->save();
        app(OrderPickupRecoveryService::class)->reoffer($order, $admin, 'إعادة طرح بعد انتهاء الوصول الثاني.');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->courier_id);
        $this->assertSame($firstBudgetBefore, (int) $firstCourier->wallet->fresh()->budget_balance);
        $this->assertSame($secondBudgetBefore, (int) $secondCourier->wallet->fresh()->budget_balance);
        $this->assertSame(1, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('user_id', $firstCourier->id)
            ->where('type', 'budget_release')
            ->count());
        $this->assertSame(1, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('user_id', $secondCourier->id)
            ->where('type', 'budget_release')
            ->where('amount', $price)
            ->count());
    }

    private function makeSecondCourier(User $reference, int $provinceId): User
    {
        $courier = User::withoutGlobalScopes()->create([
            'tenant_id' => $reference->tenant_id,
            'name' => 'المندوب الثاني',
            'username' => 'courier-second',
            'phone' => '07729999999',
            'password' => '123456',
            'role' => 'courier',
            'status' => 'active',
            'vehicle' => 'bike',
            'is_online' => true,
        ]);
        $courier->provinces()->sync([$provinceId => ['is_primary' => true]]);
        $courier->wallet()->create([
            'balance' => 150000,
            'budget' => 500000,
            'budget_balance' => 500000,
        ]);

        return $courier;
    }

    private function makePendingOrder(User $merchant, int $provinceId, string $trackNo, int $price): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار إعادة الطرح',
            'customer_name_en' => 'Re-offer test customer',
            'phone' => '07700000111',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'delivery_vehicle' => 'normal',
            'price' => $price,
            'fee' => 5000,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $provinceId,
            'date' => today(),
        ]);
    }

    public function test_pricing_uses_the_merchant_primary_province_as_route_origin(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $origin = $merchant->provinces()->wherePivot('is_primary', true)->firstOrFail();
        $otherOrigin = Province::query()->whereKeyNot($origin->id)->firstOrFail();
        $destination = Province::query()->whereKeyNot($origin->id)->orderByDesc('id')->firstOrFail();
        $platform = Tenant::platform();

        PricingRule::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'origin_province_id' => $otherOrigin->id,
            'destination_province_id' => $destination->id,
            'vehicle' => 'normal',
            'base_fee' => 9999,
            'return_fee' => 1111,
            'priority' => 1,
            'is_active' => true,
            'name_ar' => 'قاعدة مصدر خاطئ',
        ]);
        $matching = PricingRule::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'origin_province_id' => $origin->id,
            'destination_province_id' => $destination->id,
            'vehicle' => 'normal',
            'base_fee' => 3200,
            'return_fee' => 800,
            'priority' => 10,
            'is_active' => true,
            'name_ar' => 'قاعدة المصدر الصحيح',
        ]);

        $quote = app(PricingService::class)->quote($merchant, $destination->id, null, 'normal', 0, 0);

        $this->assertSame($matching->id, $quote['rule']?->id);
        $this->assertSame(3200, $quote['fee']);
        $this->assertSame(800, $quote['return_fee']);
    }
}
