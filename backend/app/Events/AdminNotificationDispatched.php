<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired only after the in-app notification rows and their campaign have been
 * committed.  Push delivery is intentionally not performed here: a listener
 * can safely use the recipient and delivery IDs without risking a push for a
 * rolled-back campaign.
 */
class AdminNotificationDispatched
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<int, int> $recipientUserIds
     * @param array<int, int|null> $recipientTenantIds
     * @param array<int, int> $notificationIds
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly array $recipientUserIds,
        public readonly array $recipientTenantIds,
        public readonly array $notificationIds,
    ) {
    }
}
