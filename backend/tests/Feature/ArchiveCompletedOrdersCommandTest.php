<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ArchiveCompletedOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
    }

    public function test_completed_order_archive_command_is_scheduled_daily_in_the_app_timezone(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'orders:archive-completed'));

        $this->assertNotNull($event);
        $this->assertSame('5 0 * * *', $event->expression);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    public function test_nightly_archive_marks_only_delivered_and_returned_orders_across_all_tenants(): void
    {
        $firstTenant = Tenant::create([
            'slug' => 'archive-command-first',
            'name' => 'تاجر الأرشفة الأول',
            'kind' => 'merchant',
            'status' => 'active',
        ]);
        $secondTenant = Tenant::create([
            'slug' => 'archive-command-second',
            'name' => 'تاجر الأرشفة الثاني',
            'kind' => 'merchant',
            'status' => 'active',
        ]);

        $delivered = $this->order($firstTenant, 'AUTO-ARCHIVE-DELIVERED', 'delivered');
        $returned = $this->order($secondTenant, 'AUTO-ARCHIVE-RETURNED', 'returned');
        $existingArchivedAt = now()->subDays(3)->format('Y-m-d H:i:s');
        $alreadyArchived = $this->order(
            $secondTenant,
            'AUTO-ARCHIVE-PRESERVED',
            'delivered',
            $existingArchivedAt,
        );

        $nonArchivables = [];
        foreach (['pending', 'approved', 'courier', 'cancelled', 'damaged', 'rejected'] as $status) {
            $nonArchivables[$status] = $this->order(
                $status === 'pending' ? $firstTenant : $secondTenant,
                'AUTO-ARCHIVE-'.strtoupper($status),
                $status,
            );
        }

        // A scheduler invocation has no signed-in user, but a stale tenant
        // context must not limit it to the currently selected tenant.
        TenantContext::set($firstTenant);
        try {
            $exitCode = Artisan::call('orders:archive-completed');
            $firstOutput = Artisan::output();
        } finally {
            TenantContext::clear();
        }

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Archived 2 completed order(s).', $firstOutput);
        $this->assertNotNull($delivered->fresh()->archived_at);
        $this->assertNotNull($returned->fresh()->archived_at);
        $this->assertSame(
            $existingArchivedAt,
            $alreadyArchived->fresh()->archived_at?->format('Y-m-d H:i:s'),
        );

        foreach ($nonArchivables as $order) {
            $this->assertNull($order->fresh()->archived_at);
        }

        $firstRunArchivedAt = $delivered->fresh()->archived_at?->format('Y-m-d H:i:s');
        TenantContext::set($secondTenant);
        try {
            $secondExitCode = Artisan::call('orders:archive-completed');
            $secondOutput = Artisan::output();
        } finally {
            TenantContext::clear();
        }

        $this->assertSame(0, $secondExitCode);
        $this->assertStringContainsString('Archived 0 completed order(s).', $secondOutput);
        $this->assertSame($firstRunArchivedAt, $delivered->fresh()->archived_at?->format('Y-m-d H:i:s'));
    }

    private function order(Tenant $tenant, string $trackNo, string $status, ?string $archivedAt = null): Order
    {
        $stage = match ($status) {
            'pending' => 'created',
            'approved' => 'awaiting_pickup',
            'courier' => 'picked_up',
            'delivered' => 'delivered',
            'returned' => 'returned',
            default => $status,
        };

        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار الأرشفة',
            'customer_name_en' => 'Archive test customer',
            'phone' => '07700000222',
            'address_ar' => 'عنوان اختبار الأرشفة',
            'address_en' => 'Archive test address',
            'price' => 25_000,
            'fee' => 3_000,
            'status' => $status,
            'workflow_stage' => $stage,
            'delivered_at' => $status === 'delivered' ? now()->subMinute() : null,
            'returned_at' => $status === 'returned' ? now()->subMinute() : null,
            'archived_at' => $archivedAt,
            'date' => today(),
        ]);
    }
}
