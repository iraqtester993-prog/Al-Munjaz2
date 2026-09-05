<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Scopes\TenantScope;
use Illuminate\Console\Command;

class ArchiveCompletedOrders extends Command
{
    protected $signature = 'orders:archive-completed';

    protected $description = 'Archive all delivered and returned orders that are not already archived';

    public function handle(): int
    {
        $archivedAt = now();

        // Run outside one tenant context so the nightly task covers every
        // merchant and courier account. Keep the soft-delete scope intact so
        // withdrawn records are never touched.
        $archived = Order::withoutGlobalScope(TenantScope::class)
            ->whereIn('status', Order::ARCHIVABLE_STATUSES)
            ->whereNull('archived_at')
            ->update([
                'archived_at' => $archivedAt,
                'updated_at' => $archivedAt,
            ]);

        $this->components->info("Archived {$archived} completed order(s).");

        return self::SUCCESS;
    }
}
