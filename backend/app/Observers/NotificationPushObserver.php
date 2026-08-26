<?php

namespace App\Observers;

use App\Models\Notification;
use App\Services\WebPushNotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Makes browser push a delivery channel for every persisted user inbox row:
 * dashboard campaigns, account updates, and operational order changes.
 */
class NotificationPushObserver
{
    public function __construct(private readonly WebPushNotificationService $push)
    {
    }

    public function created(Notification $notification): void
    {
        $notificationId = $notification->id;
        $deliver = function () use ($notificationId): void {
            $stored = Notification::withoutGlobalScopes()->find($notificationId);
            if ($stored) {
                $this->push->send($stored);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($deliver);
            return;
        }

        $deliver();
    }
}
