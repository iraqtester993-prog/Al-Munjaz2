<?php

namespace Tests\Feature;

use App\Http\Controllers\App\ChatController;
use App\Models\Branch;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BranchScopedChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();

        Route::get('/__tests/branch-dashboard/chat', [ChatController::class, 'adminIndex'])->middleware('web');
        Route::get('/__tests/branch-dashboard/chat/{chat}', [ChatController::class, 'adminShow'])
            ->middleware(['web', SubstituteBindings::class]);
        Route::get('/__tests/branch-dashboard/chat/{chat}/messages', [ChatController::class, 'adminMessages'])
            ->middleware(['web', SubstituteBindings::class]);
        Route::post('/__tests/branch-dashboard/chat/{chat}/send', [ChatController::class, 'adminSend'])
            ->middleware(['web', SubstituteBindings::class]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_branch_dashboard_chat_serialises_only_local_threads(): void
    {
        $localBranch = $this->branch('BGD-CHAT', 'بغداد', 1);
        $foreignBranch = $this->branch('BSR-CHAT', 'البصرة', 2);
        $manager = $this->manager($localBranch, 'chat-local-manager');
        $localMerchant = $this->user('merchant', 'chat-local-merchant', $localBranch->id);
        $localCourier = $this->user('courier', 'chat-local-courier', $localBranch->id);
        $foreignMerchant = $this->user('merchant', 'chat-foreign-merchant', $foreignBranch->id);
        $foreignCourier = $this->user('courier', 'chat-foreign-courier', $foreignBranch->id);

        $localOrder = $this->order('CHAT-LOCAL', $localBranch, $localMerchant, $localCourier);
        $foreignOrder = $this->order('CHAT-FOREIGN', $foreignBranch, $foreignMerchant, $foreignCourier);
        $crossBranchOrder = $this->order('CHAT-CROSS', $localBranch, $localMerchant, $foreignCourier, $foreignBranch);

        $localSupport = $this->chat('support', $localMerchant);
        $foreignSupport = $this->chat('support', $foreignMerchant);
        $localDirect = $this->chat('order_chat', $localMerchant, $localCourier, $localOrder);
        $foreignDirect = $this->chat('order_chat', $foreignMerchant, $foreignCourier, $foreignOrder);
        $crossDirect = $this->chat('order_chat', $localMerchant, $foreignCourier, $crossBranchOrder);
        $crossSupport = $this->chat('order_support', $foreignMerchant, $foreignCourier, $crossBranchOrder, 'دعم الطلب — '.$foreignCourier->name);

        $response = $this->actingAs($manager)
            ->get('/__tests/branch-dashboard/chat')
            ->assertOk();
        $props = $response->inertiaPage()['props'];

        $supportIds = collect($props['supportChats'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $directIds = collect($props['merchantCourierChats'])->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($localSupport->id, $supportIds);
        $this->assertNotContains($crossSupport->id, $supportIds);
        $this->assertNotContains($foreignSupport->id, $supportIds);
        $this->assertContains($localDirect->id, $directIds);
        $this->assertNotContains($crossDirect->id, $directIds);
        $this->assertNotContains($foreignDirect->id, $directIds);

        $this->actingAs($manager)
            ->get('/__tests/branch-dashboard/chat/'.$foreignDirect->id)
            ->assertNotFound();
        $this->actingAs($manager)
            ->get('/__tests/branch-dashboard/chat/'.$crossDirect->id)
            ->assertNotFound();
        $this->actingAs($manager)
            ->get('/__tests/branch-dashboard/chat/'.$foreignDirect->id.'/messages')
            ->assertNotFound();
        $this->actingAs($manager)
            ->post('/__tests/branch-dashboard/chat/'.$crossSupport->id.'/send', ['text' => 'رسالة غير مسموحة'])
            ->assertNotFound();
    }

    private function branch(string $code, string $provinceName, int $sortOrder): Branch
    {
        $province = Province::create([
            'name_ar' => $provinceName,
            'name_en' => $provinceName,
            'name_ku' => $provinceName,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);

        return Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'is_platform_managed' => true,
            'code' => $code,
            'name_ar' => 'فرع '.$provinceName,
            'province_id' => $province->id,
            'is_active' => true,
        ]);
    }

    private function manager(Branch $branch, string $username): User
    {
        $manager = $this->user('branch_manager', $username, $branch->id);
        app(BranchDashboardContext::class)->assignPrimaryMembership($manager, $branch);

        return $manager->fresh();
    }

    private function user(string $role, string $username, int $branchId): User
    {
        return User::create([
            'tenant_id' => Tenant::platform()->id,
            'branch_id' => $branchId,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => '079'.str_pad((string) (10000000 + User::withoutGlobalScopes()->count()), 8, '0', STR_PAD_LEFT),
            'password' => 'StrongPassword123!',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function order(string $trackNo, Branch $branch, User $merchant, User $courier, ?Branch $destination = null): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار',
            'phone' => '07800000000',
            'address_ar' => 'عنوان اختبار',
            'price' => 15000,
            'fee' => 2000,
            'status' => 'pending',
            'branch_id' => $branch->id,
            'origin_branch_id' => $branch->id,
            'destination_branch_id' => $destination?->id,
            'merchant_id' => $merchant->id,
            'courier_id' => $courier->id,
            'province_id' => $branch->province_id,
            'date' => today(),
        ]);
    }

    private function chat(string $type, User $user, ?User $counterparty = null, ?Order $order = null, ?string $title = null): Chat
    {
        return Chat::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'user_id' => $user->id,
            'counterparty_id' => $counterparty?->id,
            'counterparty_type' => $type,
            'order_id' => $order?->id,
            'title_ar' => $title ?? 'دعم فني',
            'title_en' => 'Support',
            'last_message' => 'رسالة اختبار',
            'last_at' => now(),
        ]);
    }
}
